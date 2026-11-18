#Requires -Version 5.1
#Requires -RunAsAdministrator
[CmdletBinding()]
param(
    [Parameter(Mandatory)][uri]$Server,
    [Parameter(Mandatory)][string]$PackagePath,
    [Parameter(Mandatory)][ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$')][string]$Release,
    [string]$EnrollmentDirectory,
    [uri]$EnrollmentServer,
    [ValidateSet('System','Direct','Custom')][string]$ProxyMode,
    [uri]$ProxyUri,
    [switch]$ProxyUseDefaultCredentials
)
$ErrorActionPreference = 'Stop'
if ($Server.Scheme -ne 'https') { throw 'Server must use HTTPS.' }
if ($EnrollmentServer -and $EnrollmentServer.Scheme -ne 'https') { throw 'EnrollmentServer must use HTTPS.' }
if ($EnrollmentDirectory -and $EnrollmentServer) { throw 'Select one enrollment method.' }
$name = 'DochazkaAgent'
$data = Join-Path $env:ProgramData $name
$root = Join-Path $env:ProgramFiles $name
$binary = 'Dochazka.Agent.exe'
if (!(Test-Path -LiteralPath (Join-Path $PackagePath $binary) -PathType Leaf)) { throw 'Package is incomplete.' }
$dest = Join-Path $root $Release
$config = Join-Path $data 'config.json'
# Keep credentials and the offline queue readable only by SYSTEM and local administrators.
if (!(Test-Path -LiteralPath $data)) {
    $acl = New-Object System.Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach ($sid in @('S-1-5-18','S-1-5-32-544')) {
        $identity = New-Object System.Security.Principal.SecurityIdentifier($sid)
        $acl.AddAccessRule((New-Object System.Security.AccessControl.FileSystemAccessRule(
            $identity,'FullControl','ContainerInherit,ObjectInherit','None','Allow')))
    }
    [IO.Directory]::CreateDirectory($data,$acl) | Out-Null
}
if ((Get-Item -LiteralPath $data).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Unexpected data directory.' }
$existingAcl = Get-Acl -LiteralPath $data
$allowed = @('S-1-5-18','S-1-5-32-544')
if ($existingAcl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $allowed) { throw 'Unexpected data directory owner.' }
foreach ($rule in $existingAcl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])) {
    if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin $allowed) { throw 'Unexpected data directory permissions.' }
}
$existing = if (Test-Path -LiteralPath $config) { Get-Content -LiteralPath $config -Raw | ConvertFrom-Json }
if ($existing -and $existing.Server.TrimEnd('/') -ne $Server.AbsoluteUri.TrimEnd('/')) {
    throw 'Installed agent points to a different central server.'
}
if (!$ProxyMode) {
    $ProxyMode = if ($ProxyUri) { 'Custom' } elseif ($existing.ProxyMode) { $existing.ProxyMode } else { 'System' }
}
# ValidateSet is case-insensitive. Persist the exact mode expected by the agent.
$ProxyMode = @('System','Direct','Custom') -eq $ProxyMode | Select-Object -First 1
if ($ProxyMode -eq 'Custom' -and !$ProxyUri -and $existing.ProxyUri) {
    $ProxyUri = [uri]$existing.ProxyUri
}
if ($ProxyMode -eq 'Custom') {
    if (!$ProxyUri -or !$ProxyUri.IsAbsoluteUri -or $ProxyUri.Scheme -ne 'http' -or !$ProxyUri.Host -or
        $ProxyUri.UserInfo -or $ProxyUri.AbsolutePath -ne '/' -or $ProxyUri.Query -or $ProxyUri.Fragment) {
        throw 'Custom proxy must be an HTTP address without credentials or a path, e.g. http://proxy.example.cz:3128.'
    }
} elseif ($ProxyUri) {
    throw 'ProxyUri requires custom proxy mode.'
}
$proxyAuth = if ($PSBoundParameters.ContainsKey('ProxyUseDefaultCredentials')) {
    [bool]$ProxyUseDefaultCredentials
} elseif (!$PSBoundParameters.ContainsKey('ProxyMode') -and !$PSBoundParameters.ContainsKey('ProxyUri')) {
    [bool]$existing.ProxyUseDefaultCredentials
} else { $false }
if ($ProxyMode -eq 'Direct' -and $proxyAuth) { throw 'Direct connection does not accept proxy credentials.' }
$proxyAddress = if ($ProxyUri) { $ProxyUri.AbsoluteUri }
$storedMode = if ($existing.ProxyMode) { $existing.ProxyMode } else { 'System' }
$proxyChanged = $existing -and (
    $storedMode -ne $ProxyMode -or $existing.ProxyUri -ne $proxyAddress -or
    [bool]$existing.ProxyUseDefaultCredentials -ne $proxyAuth
)

if (!$existing) {
    $request = @{ Method='Post'; MaximumRedirection=0; TimeoutSec=30; ContentType='application/json' }
    if ($EnrollmentServer) {
        if ([Security.Principal.WindowsIdentity]::GetCurrent().User.Value -ne 'S-1-5-18') { throw 'Domain enrollment requires the LocalSystem computer startup context.' }
        $request.Uri = $EnrollmentServer.AbsoluteUri.TrimEnd('/') + '/api/v1/domain-enroll'
        $request.UseDefaultCredentials = $true
        $request.Body = '{}'
    } elseif ($EnrollmentDirectory) {
        $code = (Get-Content -LiteralPath (Join-Path $EnrollmentDirectory ($env:COMPUTERNAME + '.txt')) -Raw).Trim()
        $request.Uri = $Server.AbsoluteUri.TrimEnd('/') + '/api/v1/enroll'
        $request.Body = @{code=$code} | ConvertTo-Json
    } else {
        throw 'First installation requires EnrollmentServer or EnrollmentDirectory.'
    }
    $originalProxy = [Net.WebRequest]::DefaultWebProxy
    $originalCredentials = if ($originalProxy) { $originalProxy.Credentials }
    try {
        if ($ProxyMode -eq 'Custom') {
            $request.Proxy = $ProxyUri
            if ($proxyAuth) { $request.ProxyUseDefaultCredentials = $true }
        } elseif ($ProxyMode -eq 'Direct') {
            [Net.WebRequest]::DefaultWebProxy = New-Object Net.WebProxy
        } elseif ($proxyAuth -and $originalProxy) {
            $originalProxy.Credentials = [Net.CredentialCache]::DefaultNetworkCredentials
        }
        $reply = Invoke-RestMethod @request
    } finally {
        if ($originalProxy) { $originalProxy.Credentials = $originalCredentials }
        [Net.WebRequest]::DefaultWebProxy = $originalProxy
    }
    if ($reply.token -cnotmatch '^[a-f0-9]{64}$') { throw 'Registration failed.' }
}
if (!$existing -or $proxyChanged) {
    $token = if ($existing) { $existing.Token } else { $reply.token }
    $json = @{
        Server=$Server.AbsoluteUri; Token=$token; ProxyMode=$ProxyMode
        ProxyUri=$proxyAddress; ProxyUseDefaultCredentials=$proxyAuth
    } | ConvertTo-Json
    [IO.File]::WriteAllText("$config.tmp",$json,(New-Object Text.UTF8Encoding($false)))
    if ($existing) {
        [IO.File]::SetCreationTimeUtc("$config.tmp", [IO.File]::GetCreationTimeUtc($config))
        [IO.File]::Replace("$config.tmp", $config, [NullString]::Value)
    } else {
        Move-Item -LiteralPath "$config.tmp" -Destination $config
    }
}
if (!(Test-Path -LiteralPath $dest)) {
    New-Item -ItemType Directory -Force $root | Out-Null
    $stage = Join-Path $root ([guid]::NewGuid().ToString())
    New-Item -ItemType Directory $stage | Out-Null
    Copy-Item -Path (Join-Path $PackagePath '*') -Destination $stage -Recurse
    Move-Item -LiteralPath $stage -Destination $dest
}
if (![Diagnostics.EventLog]::SourceExists($name)) { New-EventLog -LogName Application -Source $name }
$path = '"' + (Join-Path $dest $binary) + '"'
$service = Get-CimInstance Win32_Service -Filter "Name='$name'"
if (!$service) {
    New-Service -Name $name -DisplayName 'Docházka – sběr relací' -BinaryPathName $path -StartupType Automatic | Out-Null
} elseif ($service.PathName -ne $path) {
    Stop-Service $name
    $changed = Invoke-CimMethod -InputObject $service -MethodName Change -Arguments @{PathName=$path}
    if ($changed.ReturnValue -ne 0) { throw 'Service update failed.' }
} elseif ($proxyChanged -and $service.State -eq 'Running') {
    Stop-Service $name
}
try { Start-Service $name }
catch {
    if ($service -and $service.PathName -ne $path) {
        Invoke-CimMethod -InputObject $service -MethodName Change -Arguments @{PathName=$service.PathName} | Out-Null
        Start-Service $name
    }
    throw
}
