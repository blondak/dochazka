using System.Globalization;
using System.IO.Pipes;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Runtime.InteropServices;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;
using Microsoft.Data.Sqlite;
using Microsoft.Win32.SafeHandles;

namespace Dochazka.Agent;

internal static class ServiceSessionPipe
{
    private const string Name = "DochazkaAgent.ServiceSession";
    private const PipeAccessRights ClientRights = PipeAccessRights.ReadWrite | PipeAccessRights.Synchronize;

    public static async Task Listen(Func<Settings> loadSettings, string journalPath, CancellationToken stop)
    {
        var access = new PipeSecurity();
        access.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.NetworkSid, null), ClientRights, AccessControlType.Deny));
        access.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null), PipeAccessRights.FullControl, AccessControlType.Allow));
        access.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.InteractiveSid, null), ClientRights, AccessControlType.Allow));

        using var pipe = NamedPipeServerStreamAcl.Create(Name, PipeDirection.InOut, 1, PipeTransmissionMode.Byte,
            PipeOptions.Asynchronous, 0, 0, access);
        while (!stop.IsCancellationRequested)
        {
            await pipe.WaitForConnectionAsync(stop);
            using var request = CancellationTokenSource.CreateLinkedTokenSource(stop);
            request.CancelAfter(TimeSpan.FromSeconds(15));
            try
            {
                var command = new byte[1];
                await pipe.ReadExactlyAsync(command, request.Token);
                if (command[0] != 1) continue;
                var result = await Issue(pipe, loadSettings, journalPath, request.Token);
                await pipe.WriteAsync(Encoding.ASCII.GetBytes(result), request.Token);
            }
            catch (IOException) { }
            catch (OperationCanceledException) when (!stop.IsCancellationRequested) { }
            finally { pipe.Disconnect(); }
        }
    }

    public static async Task<string> RequestCode(CancellationToken stop)
    {
        using var pipe = new NamedPipeClientStream(".", Name, ClientRights, PipeOptions.Asynchronous,
            TokenImpersonationLevel.Identification, HandleInheritability.None);
        await pipe.ConnectAsync(2000, stop);
        await pipe.WriteAsync(new byte[] { 1 }, stop);
        var status = new byte[1];
        await pipe.ReadExactlyAsync(status, stop);
        if (status[0] == '1') throw new InvalidOperationException("Relace ještě není v centrále. Zkuste to po další dávce docházky.");
        if (status[0] != '0') throw new InvalidOperationException("Služba nemohla vytvořit odkaz v centrále.");
        var token = new byte[64];
        await pipe.ReadExactlyAsync(token, stop);
        var code = Encoding.ASCII.GetString(token);
        return IsCode(code) ? code : throw new InvalidDataException();
    }

    private static async Task<string> Issue(NamedPipeServerStream pipe, Func<Settings> loadSettings, string journalPath, CancellationToken stop)
    {
        try
        {
            string? sid = null, logon = null;
            pipe.RunAsClient(() =>
            {
                using var identity = WindowsIdentity.GetCurrent(ifImpersonating: true);
                sid = identity?.User?.Value;
                if (identity == null || !GetTokenInformation(identity.AccessToken, 10, out var data,
                    Marshal.SizeOf<TokenStatistics>(), out _)) return;
                logon = FormatLogon(data.AuthenticationId.High, data.AuthenticationId.Low);
            });
            if (sid == null || logon == null || !sid.StartsWith("S-1-5-21-", StringComparison.Ordinal)) return "2";
            var boot = Journal.Boot(DateTime.UtcNow.ToString("O", CultureInfo.InvariantCulture));
            if (boot == "unknown") return "2";
            string session;
            using (var db = new SqliteConnection(new SqliteConnectionStringBuilder
                { DataSource = journalPath, Mode = SqliteOpenMode.ReadOnly, DefaultTimeout = 2 }.ToString()))
            {
                db.Open();
                session = new Journal(db).ResolveSessionKey(boot + "." + logon, sid);
            }
            var settings = loadSettings();
            using var http = new HttpClient(settings.CreateHandler()) { Timeout = TimeSpan.FromSeconds(10) };
            http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", settings.Token);
            using var response = await http.PostAsJsonAsync(settings.Server.TrimEnd('/') + "/api/v1/service-session-link",
                new { sid, session }, stop);
            if (response.StatusCode == HttpStatusCode.Conflict) return "1";
            if (!response.IsSuccessStatusCode) return "2";
            var result = await response.Content.ReadFromJsonAsync<SessionLink>(stop);
            return result?.Code is { } code && IsCode(code) ? "0" + code : "2";
        }
        catch (Exception error) when (error is IOException or HttpRequestException or System.Text.Json.JsonException
            or UnauthorizedAccessException or InvalidOperationException or OperationCanceledException or System.ComponentModel.Win32Exception
            or SqliteException)
        {
            return "2";
        }
    }

    private static bool IsCode(string code) => code.Length == 64 && code.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
    internal static string FormatLogon(int high, uint low) => "0x" + (unchecked((ulong)(uint)high << 32) | low).ToString("x", CultureInfo.InvariantCulture);

    private record SessionLink(string Code);

    [StructLayout(LayoutKind.Sequential)]
    private struct Luid
    {
        public uint Low;
        public int High;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct TokenStatistics
    {
        public Luid TokenId, AuthenticationId;
        public long Expiration;
        public int Type, ImpersonationLevel;
        public uint DynamicCharged, DynamicAvailable, GroupCount, PrivilegeCount;
        public Luid ModifiedId;
    }

    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetTokenInformation(SafeAccessTokenHandle token, int informationClass,
        out TokenStatistics value, int size, out int required);
}
