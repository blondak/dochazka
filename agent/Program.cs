using System.Diagnostics;
using System.Diagnostics.Eventing.Reader;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.ServiceProcess;
using System.Text.Json;
using System.Xml.Linq;
using Microsoft.Data.Sqlite;

namespace Dochazka.Agent;

record Settings(string Server, string Token, string ProxyMode = "System", string? ProxyUri = null, bool ProxyUseDefaultCredentials = false)
{
    public HttpClientHandler CreateHandler()
    {
        var handler = new HttpClientHandler { AllowAutoRedirect = false };
        switch (ProxyMode)
        {
            case "System":
                if (ProxyUseDefaultCredentials) handler.DefaultProxyCredentials = CredentialCache.DefaultNetworkCredentials;
                break;
            case "Direct":
                handler.UseProxy = false;
                break;
            case "Custom" when Uri.TryCreate(ProxyUri, UriKind.Absolute, out var uri)
                && uri.Scheme == Uri.UriSchemeHttp && uri.Host.Length > 0 && uri.UserInfo.Length == 0
                && uri.AbsolutePath == "/" && uri.Query.Length == 0 && uri.Fragment.Length == 0:
                handler.Proxy = new WebProxy(uri) { UseDefaultCredentials = ProxyUseDefaultCredentials };
                break;
            default:
                handler.Dispose();
                throw new InvalidDataException("Neplatné nastavení proxy agenta.");
        }
        return handler;
    }
}
record Signal(string Id, string Kind, string At, string Sid, string Session);
record Receipt(string Id, string Status);
record Reply(Receipt[] Results);

internal sealed class AttendanceService : ServiceBase
{
    private readonly CancellationTokenSource stop = new();
    private readonly TaskCompletionSource<CancellationToken> shutdown = new(TaskCreationOptions.RunContinuationsAsynchronously);
    private Task? worker;
    private Task? serviceSessions;

    [STAThread]
    public static void Main(string[] args)
    {
        if (args is ["--tray", var server])
        {
            Tray.Run(server);
            return;
        }
        if (args.SequenceEqual(new[] { "--self-test" }))
        {
            if (ServiceSessionPipe.FormatLogon(0, 0x1b) != "0x1b" || ServiceSessionPipe.FormatLogon(1, 0) != "0x100000000")
                throw new InvalidDataException("Unexpected Windows logon ID format.");
            using var systemHandler = new Settings("", "").CreateHandler();
            using var directHandler = new Settings("", "", "Direct").CreateHandler();
            using var customHandler = new Settings("", "", "Custom", "http://proxy.example.test:3128", true).CreateHandler();
            if (!systemHandler.UseProxy || systemHandler.Proxy != null || directHandler.UseProxy
                || customHandler.Proxy is not WebProxy { UseDefaultCredentials: true })
                throw new InvalidDataException("Proxy settings were not applied.");
            using var db = new SqliteConnection("Data Source=:memory:");
            db.Open();
            var journal = new Journal(db);
            journal.Initialize();
            journal.Warn("test");
            if (journal.Warning() != "test") throw new InvalidDataException("SQLite journal unavailable.");
            journal.Confirm([], [], "test");
            if (journal.Warning() != null) throw new InvalidDataException("SQLite confirmation failed.");
            var signal = new Signal("1", "login", "2026-01-05T08:00:00Z", "S-1-5-21-1-2-3-44", "boot.1");
            VerifyDesktopLogons();
            using var insert = db.CreateCommand();
            insert.CommandText = "INSERT INTO queue(id,payload) VALUES('1',$payload)";
            insert.Parameters.AddWithValue("$payload", JsonSerializer.Serialize(signal));
            insert.ExecuteNonQuery();
            journal.Confirm(journal.Batch(), [new Receipt("1", "unmapped")], null);
            if (journal.Batch().Count != 0 || journal.Warning() == null) throw new InvalidDataException("Unmapped signal should be deferred.");
            using var retry = db.CreateCommand();
            retry.CommandText = "UPDATE deferred SET retry_at=0";
            retry.ExecuteNonQuery();
            var batch = journal.Batch();
            if (!batch.SequenceEqual(new[] { signal })) throw new InvalidDataException("Deferred signal lost.");
            journal.Confirm(batch, [new Receipt("1", "accepted")], journal.Warning());
            if (journal.Batch().Count != 0 || journal.Warning() != null || journal.ResolvedWarning() == null) throw new InvalidDataException("Confirmed signal retained.");
            journal.Confirm([], [], null, journal.ResolvedWarning());
            if (journal.ResolvedWarning() != null) throw new InvalidDataException("Resolution confirmation failed.");
            insert.ExecuteNonQuery();
            journal.Confirm(journal.Batch(), [new Receipt("1", "ignored")], null);
            if (journal.Batch().Count != 0 || journal.Warning() != null) throw new InvalidDataException("Ignored signal retained.");
            VerifyShutdownDelivery(journal, insert);
            return;
        }
        Run(new AttendanceService { ServiceName = "DochazkaAgent", CanShutdown = true });
    }

    private static void VerifyDesktopLogons()
    {
        const string sid = "S-1-5-21-1-2-3-44";
        const string at = "2026-01-05T08:00:00Z";
        const string after = "2026-01-05T16:00:00Z";
        const string winlogon = @"C:\Windows\System32\winlogon.exe";
        const string serviceHost = @"C:\Windows\System32\svchost.exe";
        static Journal.DesktopAccount? Parse(int eventId, string type, string provider, string process, string logon,
            string linked = "0x0", string elevated = "%%1843") => Journal.InteractiveAccount(eventId, XElement.Parse($"""
            <Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><EventData>
                <Data Name="LogonType">{type}</Data><Data Name="TargetUserSid">{sid}</Data>
                <Data Name="TargetLogonId">{logon}</Data><Data Name="TargetLinkedLogonId">{linked}</Data>
                <Data Name="LogonProcessName">{provider}</Data><Data Name="ProcessName">{process}</Data>
                <Data Name="ElevatedToken">{elevated}</Data>
            </EventData></Event>
            """));

        if (Parse(4624, "2", "User32 ", winlogon, "0x001B") is not { LogonId: "0x1b" }
            || Parse(4624, "11", "User32", serviceHost, "0x1c") == null
            || Parse(4624, "2", "User32", winlogon, "0x1d", elevated: "%%1842") == null
            || Parse(4624, "2", "seclogo", serviceHost, "0x1b") != null
            || Parse(4624, "2", "Advapi", serviceHost, "0x1b") != null
            || Parse(4624, "2", "User32", @"C:\Windows\System32\consent.exe", "0x1b") != null
            || Parse(4624, "9", "seclogo", serviceHost, "0x1b") != null
            || Parse(4648, "2", "User32", winlogon, "0x1b") != null
            || new[] { "3", "5", "7", "10" }.Any(type => Parse(4624, type, "User32", winlogon, "0x1b") != null))
            throw new InvalidDataException("Unexpected classification of desktop and UAC logons.");

        using var db = new SqliteConnection("Data Source=:memory:");
        db.Open();
        var journal = new Journal(db);
        journal.Initialize();
        void Record(int eventId, string id, string logon, string linked = "0x0", string elevated = "%%1843")
        {
            using var tx = db.BeginTransaction();
            var account = Parse(eventId, "2", eventId == 4624 ? "User32" : "", eventId == 4624 ? winlogon : "", logon, linked, elevated)!;
            journal.RecordSecurity(eventId, account, "boot", id, eventId == 4624 ? at : after, tx);
            tx.Commit();
        }

        Record(4634, "orphan", "0x19");
        Record(4624, "first", "0x1a", elevated: "%%1842");
        Record(4624, "linked", "0x1b", "0x1a");
        Record(4634, "elevated", "0x1a");
        if (!journal.Batch().SequenceEqual(new[] { new Signal("first", "login", at, sid, "boot.0x1a") })
            || journal.ResolveSessionKey("boot.0x1b", sid) != "boot.0x1a")
            throw new InvalidDataException("UAC produced a second desktop session or closed the real one early.");
        Record(4634, "last", "0x1b");
        Record(4634, "duplicate", "0x1b");
        if (!journal.Batch().SequenceEqual(new[] {
            new Signal("first", "login", at, sid, "boot.0x1a"), new Signal("last", "logout", after, sid, "boot.0x1a") }))
            throw new InvalidDataException("Desktop logoff was not paired with the original session.");

        Record(4624, "first-link", "0x2a", "0x2b", "%%1842");
        Record(4624, "companion-without-link", "0x2b");
        Record(4634, "elevated-with-link", "0x2a");
        Record(4634, "last-link", "0x2b");
        if (!journal.Batch().Skip(2).SequenceEqual(new[] {
            new Signal("first-link", "login", at, sid, "boot.0x2a"), new Signal("last-link", "logout", after, sid, "boot.0x2a") }))
            throw new InvalidDataException("Desktop logons were duplicated when the first event contained the link.");

        Record(4624, "normal-login", "0x3a");
        Record(4634, "normal-logoff", "0x3a");
        if (!journal.Batch().Skip(4).SequenceEqual(new[] {
            new Signal("normal-login", "login", at, sid, "boot.0x3a"), new Signal("normal-logoff", "logout", after, sid, "boot.0x3a") }))
            throw new InvalidDataException("An ordinary desktop session did not produce a login and logoff.");
    }

    protected override void OnStart(string[] args)
    {
        worker = Task.Run(() => Loop(stop.Token));
        serviceSessions = Task.Run(() => ServiceSessionPipe.Listen(LoadSettings, JournalPath, stop.Token));
        _ = serviceSessions.ContinueWith(failed =>
            EventLog.WriteEntry(ServiceName, failed.Exception!.GetBaseException().Message, EventLogEntryType.Warning),
            CancellationToken.None, TaskContinuationOptions.OnlyOnFaulted, TaskScheduler.Default);
        _ = worker.ContinueWith(failed =>
        {
            try { EventLog.WriteEntry(ServiceName, failed.Exception!.GetBaseException().Message, EventLogEntryType.Error); }
            finally
            {
                // SCM must reflect a stopped collector if local configuration or storage fails.
                ExitCode = 1064;
                Stop();
            }
        }, CancellationToken.None, TaskContinuationOptions.OnlyOnFaulted, TaskScheduler.Default);
    }
    protected override void OnStop()
    {
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(15));
        StopAndWait(deadline.Token);
    }

    protected override void OnShutdown()
    {
        using var deadline = new CancellationTokenSource(ShutdownDelivery.Budget);
        shutdown.TrySetResult(deadline.Token);
        StopAndWait(deadline.Token);
    }

    private void StopAndWait(CancellationToken deadline)
    {
        _ = stop.CancelAsync();
        if (worker != null && serviceSessions != null)
            Task.WhenAny(Task.WhenAll(worker, serviceSessions), Task.Delay(Timeout.InfiniteTimeSpan, deadline)).GetAwaiter().GetResult();
    }

    private static string DataPath => Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "DochazkaAgent");
    private static string JournalPath => Path.Combine(DataPath, "fronta.db");

    private static Settings LoadSettings() =>
        JsonSerializer.Deserialize<Settings>(File.ReadAllText(Path.Combine(DataPath, "config.json"))) ?? throw new InvalidDataException();

    private async Task Loop(CancellationToken cancel)
    {
        var path = DataPath;
        var configPath = Path.Combine(path, "config.json");
        var config = LoadSettings();
        var installed = File.GetCreationTimeUtc(configPath);
        var uri = new Uri(config.Server.TrimEnd('/') + "/api/v1/events");
        if (uri.Scheme != Uri.UriSchemeHttps) throw new InvalidDataException("Je vyžadováno HTTPS.");
        using var http = new HttpClient(config.CreateHandler()) { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", config.Token);
        using var db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = JournalPath }.ToString());
        db.Open();
        var store = new Journal(db);
        store.Initialize();
        while (!cancel.IsCancellationRequested)
        {
            var fault = CollectEvents(store, installed, cancel);
            try
            {
                await SendBatch(store, http, uri, sendHeartbeat: true, cancel);
            }
            catch (OperationCanceledException) when (cancel.IsCancellationRequested) { break; }
            catch (Exception e) { fault = e.Message; }
            if (fault != null) EventLog.WriteEntry("DochazkaAgent", fault, EventLogEntryType.Warning);
            try { await Task.Delay(TimeSpan.FromSeconds(30), cancel); }
            catch (OperationCanceledException) { break; }
        }

        if (shutdown.Task.IsCompletedSuccessfully)
        {
            var deadline = shutdown.Task.Result;
            try
            {
                await ShutdownDelivery.Run(
                    token => CollectEvents(store, installed, token),
                    token => SendBatch(store, http, uri, sendHeartbeat: false, token),
                    deadline);
            }
            catch (OperationCanceledException) when (deadline.IsCancellationRequested) { }
        }
    }

    private static string? CollectEvents(Journal store, DateTime installed, CancellationToken cancel)
    {
        try
        {
            store.Collect("Security", installed, cancel);
            if (!cancel.IsCancellationRequested) store.Collect("System", installed, cancel);
            return null;
        }
        catch (Exception error)
        {
            if (cancel.IsCancellationRequested) return null;
            store.Warn("Výpadek sběru: " + error.Message);
            return error.Message;
        }
    }

    private static async Task SendBatch(Journal store, HttpClient http, Uri uri, bool sendHeartbeat, CancellationToken cancel)
    {
        cancel.ThrowIfCancellationRequested();
        var batch = store.Batch();
        var warning = store.Warning();
        var resolved = store.ResolvedWarning();
        if (!sendHeartbeat && batch.Count == 0 && warning == null && resolved == null) return;
        using var response = await http.PostAsJsonAsync(uri, new { events = batch, warning, resolved_warning = resolved }, cancel);
        response.EnsureSuccessStatusCode();
        var reply = await response.Content.ReadFromJsonAsync<Reply>(cancel);
        if (reply?.Results is not { } receipts) throw new InvalidDataException("Chybí potvrzení přijaté dávky.");
        store.Confirm(batch, receipts, warning, resolved);
    }

    private static void VerifyShutdownDelivery(Journal journal, SqliteCommand insert)
    {
        var collections = 0;
        var requests = 0;
        using var deadline = new CancellationTokenSource(ShutdownDelivery.Budget);
        ShutdownDelivery.Run(_ =>
        {
            if (++collections == 2) insert.ExecuteNonQuery();
        }, async request =>
        {
            ++requests;
            await Task.Delay(Timeout.InfiniteTimeSpan, request);
        }, deadline.Token).GetAwaiter().GetResult();
        if (collections != 2 || requests != 1 || journal.Batch().Count != 1)
            throw new InvalidDataException("Shutdown did not retain the late event after a request timeout.");
    }
}
