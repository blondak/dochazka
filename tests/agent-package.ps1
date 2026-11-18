#Requires -Version 5.1
#Requires -RunAsAdministrator
param(
    [Parameter(Mandatory)][string]$PackagePath,
    [switch]$SingleFile
)

$ErrorActionPreference = 'Stop'
$package = (Resolve-Path -LiteralPath $PackagePath).Path
$files = @(Get-ChildItem -LiteralPath $package -Force | Sort-Object Name | ForEach-Object { $_.Name })
if ($files -cnotcontains 'Dochazka.Agent.exe' -or $files -cnotcontains 'Install-Agent.ps1') {
    throw 'The package is missing the agent or the installer.'
}
if ($SingleFile) {
    if ($files.Count -ne 2) { throw 'The single-file package must contain just the executable and installer.' }
} elseif ($files -cnotcontains 'Dochazka.Agent.dll' -or $files -cnotcontains 'coreclr.dll') {
    throw 'The multi-file package is missing the assembly or the .NET runtime.'
}
$installer = Join-Path $package 'Install-Agent.ps1'
$binary = Join-Path $package 'Dochazka.Agent.exe'
$parseErrors = $null
[Management.Automation.Language.Parser]::ParseFile($installer, [ref]$null, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }

$name = 'DochazkaAgent'
$data = Join-Path $env:ProgramData $name
$root = Join-Path $env:ProgramFiles $name
if ((Get-Service $name -ErrorAction SilentlyContinue) -or (Test-Path $data) -or (Test-Path $root)) {
    throw 'Use a Windows test machine without an existing attendance agent.'
}

$selfTest = Start-Process -FilePath $binary -ArgumentList '--self-test' -Wait -PassThru
if ($selfTest.ExitCode -ne 0) { throw "Agent self-test exited with code $($selfTest.ExitCode)." }
$runKey = 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Run'

try {
    # The seeded test registration keeps the smoke test local to this runner.
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $administrators = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
    $acl.SetOwner($administrators)
    foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {
        $identity = New-Object Security.Principal.SecurityIdentifier($sid)
        $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule(
            $identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
    }
    [IO.Directory]::CreateDirectory($data, $acl) | Out-Null
    $server = 'https://127.0.0.1:1'
    $token = '0' * 64
    $config = Join-Path $data 'config.json'
    # Older PowerShell installers can leave an empty array in the optional proxy URI field.
    @{Server = $server; Token = $token; ProxyMode = 'System'; ProxyUri = @()} | ConvertTo-Json |
        Set-Content -LiteralPath $config -Encoding ASCII
    & $installer -Server $server -PackagePath $package -Release 'package-test' -ProxyMode System
    $settings = Get-Content -LiteralPath $config -Raw | ConvertFrom-Json
    if ($settings.PSObject.Properties['ProxyUri'] -or $settings.Token -cne $token) {
        throw 'The installer did not repair the optional proxy URI while preserving registration.'
    }
    $tray = Get-ItemPropertyValue -Path $runKey -Name $name
    if ($tray -notlike '*Dochazka.Agent.exe" --tray "https://127.0.0.1:1/"') {
        throw 'The installer did not register the tray for interactive logon.'
    }

    $queue = Join-Path $data 'fronta.db'
    $deadline = (Get-Date).AddSeconds(20)
    while (!(Test-Path -LiteralPath $queue) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 250 }
    $service = Get-CimInstance Win32_Service -Filter "Name='$name'"
    if (!(Test-Path -LiteralPath $queue) -or $service.State -ne 'Running' -or $service.StartName -ne 'LocalSystem') {
        throw 'The installed LocalSystem service did not start collecting with its local SQLite queue.'
    }
    Write-Output 'Windows executable, SQLite, and LocalSystem service OK.'
} finally {
    Remove-ItemProperty -Path $runKey -Name $name -ErrorAction SilentlyContinue
    if (Get-Service $name -ErrorAction SilentlyContinue) {
        Stop-Service $name -ErrorAction SilentlyContinue
        sc.exe delete $name | Out-Null
    }
    foreach ($path in @($data, $root)) {
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Recurse -Force }
    }
}
