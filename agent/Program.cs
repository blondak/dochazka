using System.Diagnostics;
using System.Diagnostics.Eventing.Reader;
using System.Globalization;
using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Reflection;
using System.ServiceProcess;
using System.Text.Json;
using System.Threading.Channels;
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
    private static readonly TimeSpan CollectionInterval = TimeSpan.FromSeconds(30);
    private static readonly TimeSpan HeartbeatInterval = TimeSpan.FromMinutes(5);
    private static readonly string AgentVersion = typeof(AttendanceService).Assembly
        .GetCustomAttribute<AssemblyInformationalVersionAttribute>()!.InformationalVersion.Split('+', 2)[0];
    private readonly CancellationTokenSource stop = new();
    private readonly TaskCompletionSource<CancellationToken> shutdown = new(TaskCreationOptions.RunContinuationsAsynchronously);
    private readonly Channel<bool> desktopChanges = Channel.CreateBounded<bool>(1);
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
            if (WindowsSessions.FormatLogon(0, 0x1b) != "0x1b" || WindowsSessions.FormatLogon(1, 0) != "0x100000000")
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
            VerifyWindowsSessionNotifications();
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
            VerifyBatchDelivery(journal, insert).GetAwaiter().GetResult();
            VerifyServiceSessionConfirmation().GetAwaiter().GetResult();
            VerifyShutdownDelivery(journal, insert);
            return;
        }
        Run(new AttendanceService { ServiceName = "DochazkaAgent", CanShutdown = true, CanHandleSessionChangeEvent = true });
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

    private static void VerifyWindowsSessionNotifications()
    {
        const string boot = "123";
        const string sid = "S-1-5-21-1-2-3-44";
        const string at = "2026-01-05T08:00:00.0000000Z";
        const string later = "2026-01-05T08:01:00.0000000Z";
        const string end = "2026-01-05T16:00:00.0000000Z";
        using var db = new SqliteConnection("Data Source=:memory:");
        db.Open();
        var journal = new Journal(db);
        journal.Initialize();

        void Audit(int eventId, Journal.DesktopAccount account, string recordId, string time)
        {
            using var tx = db.BeginTransaction();
            journal.RecordSecurity(eventId, account, boot, recordId, time, tx);
            tx.Commit();
        }

        void Logon(int session, Journal.DesktopAccount account, string marker, string time, bool observed = true)
        {
            using var tx = db.BeginTransaction();
            journal.RecordWindowsLogon(session, account, marker, time, tx, observed);
            tx.Commit();
        }

        void Logoff(int session, string marker, string time)
        {
            using var tx = db.BeginTransaction();
            journal.RecordWindowsLogoff(session, marker, time, tx);
            tx.Commit();
        }

        var desktop = new Journal.DesktopAccount(sid, "0xa1", "0xa2", false);
        var elevated = new Journal.DesktopAccount(sid, "0xa2", "0xa1", true);
        Logon(1, desktop, boot, at);
        Audit(4624, elevated, "audit-elevated", at);
        Audit(4624, desktop, "audit-desktop", at);
        Audit(4634, elevated, "audit-elevated-logoff", later);
        if (journal.Batch().Count != 1)
            throw new InvalidDataException("The audit log duplicated a WTS login or ended it prematurely.");

        // The Windows ID mapping survives a service restart and works without a matching 4634.
        journal = new Journal(db);
        journal.Initialize();
        Logoff(1, "456", end);
        journal.SaveNotice(1, end, false, null);
        journal.ReplayNotices(_ => "unknown", CancellationToken.None);
        journal = new Journal(db);
        journal.ReplayNotices(time => time == end ? boot : "unknown", CancellationToken.None);
        Logoff(1, boot, end);
        Audit(4634, desktop, "audit-desktop-logoff", end);
        if (!journal.Batch().SequenceEqual(new[] {
            new Signal("wts.login.123.0xa1", "login", at, sid, "123.0xa1"),
            new Signal("wts.logout.123.0xa1", "logout", end, sid, "123.0xa1") }))
            throw new InvalidDataException("A desktop notification did not close its original session exactly once.");

        Audit(4624, elevated with { LogonId = "0xb2", LinkedLogonId = null }, "audit-first", at);
        var second = desktop with { LogonId = "0xb1", LinkedLogonId = "0xb2" };
        Logon(2, second, boot, later);
        Audit(4634, second, "audit-fallback", end);
        Logoff(2, boot, end);
        if (!journal.Batch().Skip(2).SequenceEqual(new[] {
            new Signal("audit-first", "login", at, sid, "123.0xb2"),
            new Signal("audit-fallback", "logout", end, sid, "123.0xb2") }))
            throw new InvalidDataException("Security fallback did not retain the first session key.");

        var previouslyOpen = desktop with { LogonId = "0xc1", LinkedLogonId = null };
        Audit(4624, previouslyOpen, "before-upgrade", at);
        Logon(3, previouslyOpen, boot, later, observed: false);
        Logoff(3, boot, at);
        Logoff(3, boot, end);
        Logon(4, previouslyOpen with { LogonId = "0xd1" }, boot, end, observed: false);
        if (!journal.Batch().Skip(4).SequenceEqual(new[] {
            new Signal("before-upgrade", "login", at, sid, "123.0xc1"),
            new Signal("wts.logout.123.0xc1", "logout", end, sid, "123.0xc1") }))
            throw new InvalidDataException("Startup association invented a login or accepted a stale logoff.");
    }

    protected override void OnStart(string[] args)
    {
        using var db = OpenJournal();
        new Journal(db).Initialize();
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

    protected override void OnSessionChange(SessionChangeDescription change)
    {
        if (change.SessionId <= 0 || change.Reason is not (SessionChangeReason.SessionLogon or SessionChangeReason.SessionLogoff))
            return;
        string at = DateTime.UtcNow.ToString("O", CultureInfo.InvariantCulture);
        bool isLogon = change.Reason == SessionChangeReason.SessionLogon;
        // Capture the logon token while it exists; the delivery loop may be waiting for HTTPS.
        var account = isLogon ? WindowsSessions.ReadConsoleUser(change.SessionId) : null;
        using var db = OpenJournal();
        new Journal(db).SaveNotice(change.SessionId, at, isLogon, account);
        desktopChanges.Writer.TryWrite(true);
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

    private static SqliteConnection OpenJournal()
    {
        var db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = JournalPath }.ToString());
        db.Open();
        return db;
    }

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
        using var db = OpenJournal();
        var store = new Journal(db);

        string? boot = null;
        bool bound = false;
        long? lastPoll = null;

        string? Collect(CancellationToken token, bool history)
        {
            desktopChanges.Reader.TryRead(out _);
            try
            {
                store.ReplayNotices(Journal.Boot, token);
                if (history && boot == null)
                {
                    string marker = Journal.Boot(DateTime.UtcNow.ToString("O", CultureInfo.InvariantCulture));
                    if (marker != "unknown") boot = marker;
                }
            }
            catch (Exception error) when (error is EventLogException or UnauthorizedAccessException)
            {
                store.Warn("Výpadek sběru: " + error.Message);
            }
            if (!history) return null;

            string? fault = CollectEvents(store, installed, token);
            if (boot != null && !bound && !token.IsCancellationRequested && WindowsSessions.SessionIds() is { } sessions)
            {
                // An upgrade or restart can happen while users are logged on.
                string observedAt = DateTime.UtcNow.ToString("O", CultureInfo.InvariantCulture);
                foreach (int sessionId in sessions)
                {
                    if (WindowsSessions.ReadConsoleUser(sessionId) is { } account)
                    {
                        using var tx = db.BeginTransaction();
                        store.RecordWindowsLogon(sessionId, account, boot, observedAt, tx, observed: false);
                        tx.Commit();
                    }
                }
                bound = fault == null;
            }
            return fault;
        }

        long? lastContact = null;
        while (!cancel.IsCancellationRequested)
        {
            bool historyDue = lastPoll is null || Stopwatch.GetElapsedTime(lastPoll.Value) >= CollectionInterval;
            if (historyDue) lastPoll = Stopwatch.GetTimestamp();
            var fault = Collect(cancel, historyDue);
            try
            {
                bool heartbeatDue = lastContact is null || Stopwatch.GetElapsedTime(lastContact.Value) >= HeartbeatInterval;
                if (await SendBatch(store, http, uri, heartbeatDue, cancel))
                    lastContact = Stopwatch.GetTimestamp();
            }
            catch (OperationCanceledException) when (cancel.IsCancellationRequested) { break; }
            catch (Exception e) { fault = e.Message; }
            if (fault != null)
            {
                try { EventLog.WriteEntry(ServiceName, fault, EventLogEntryType.Warning); }
                catch (Exception error) when (error is System.ComponentModel.Win32Exception or InvalidOperationException
                    or UnauthorizedAccessException or System.Security.SecurityException)
                {
                    // The event log service can also be unavailable when recording the warning.
                }
            }

            using var nextPoll = CancellationTokenSource.CreateLinkedTokenSource(cancel);
            var untilPoll = CollectionInterval - Stopwatch.GetElapsedTime(lastPoll!.Value);
            if (untilPoll <= TimeSpan.Zero) continue;
            nextPoll.CancelAfter(untilPoll);
            try { await desktopChanges.Reader.WaitToReadAsync(nextPoll.Token); }
            catch (OperationCanceledException) when (!cancel.IsCancellationRequested) { }
            catch (OperationCanceledException) { break; }
        }

        if (shutdown.Task.IsCompletedSuccessfully)
        {
            var deadline = shutdown.Task.Result;
            try
            {
                await ShutdownDelivery.Run(
                    token => Collect(token, history: true),
                    token => SendBatch(store, http, uri, sendHeartbeat: false, token),
                    deadline);
            }
            catch (OperationCanceledException) when (deadline.IsCancellationRequested) { }
        }
        else Collect(cancel, history: false);
    }

    private static string? CollectEvents(Journal store, DateTime installed, CancellationToken cancel)
    {
        string? fault = null;
        foreach (var channel in new[] { "Security", "System" })
        {
            if (cancel.IsCancellationRequested) break;
            try { store.Collect(channel, installed, cancel); }
            catch (Exception error) when (!cancel.IsCancellationRequested)
            {
                fault = $"Výpadek sběru ({channel}): {error.Message}";
                store.Warn(fault);
            }
            catch (Exception) when (cancel.IsCancellationRequested) { break; }
        }
        return fault;
    }

    private static async Task<bool> SendBatch(Journal store, HttpClient http, Uri uri, bool sendHeartbeat, CancellationToken cancel)
    {
        cancel.ThrowIfCancellationRequested();
        var batch = store.Batch();
        var warning = store.Warning();
        var resolved = store.ResolvedWarning();
        if (!sendHeartbeat && batch.Count == 0 && warning == null && resolved == null) return false;
        using var response = await http.PostAsJsonAsync(uri, new { events = batch, agent_version = AgentVersion, warning, resolved_warning = resolved }, cancel);
        response.EnsureSuccessStatusCode();
        var reply = await response.Content.ReadFromJsonAsync<Reply>(cancel);
        if (reply?.Results is not { } receipts) throw new InvalidDataException("Chybí potvrzení přijaté dávky.");
        store.Confirm(batch, receipts, warning, resolved);
        return true;
    }

    private static async Task VerifyBatchDelivery(Journal journal, SqliteCommand insert)
    {
        var handler = new DeliveryTestHandler();
        using var http = new HttpClient(handler);
        var uri = new Uri("https://example.test/api/v1/events");
        if (await SendBatch(journal, http, uri, sendHeartbeat: false, CancellationToken.None) || handler.Requests != 0)
            throw new InvalidDataException("An empty poll sent a request before the heartbeat was due.");
        if (!await SendBatch(journal, http, uri, sendHeartbeat: true, CancellationToken.None) || handler.Requests != 1)
            throw new InvalidDataException("A due heartbeat did not send an empty request.");

        insert.ExecuteNonQuery();
        handler.Status = HttpStatusCode.ServiceUnavailable;
        try
        {
            await SendBatch(journal, http, uri, sendHeartbeat: false, CancellationToken.None);
            throw new InvalidDataException("An unsuccessful delivery was not reported.");
        }
        catch (HttpRequestException error) when (error.StatusCode == HttpStatusCode.ServiceUnavailable) { }
        if (handler.Requests != 2 || journal.Batch().Count != 1)
            throw new InvalidDataException("An unconfirmed event was not retained for retry.");

        handler.Status = HttpStatusCode.OK;
        if (!await SendBatch(journal, http, uri, sendHeartbeat: false, CancellationToken.None)
            || handler.Requests != 3 || journal.Batch().Count != 0)
            throw new InvalidDataException("A queued event was not delivered independently of the heartbeat.");
    }

    private sealed class DeliveryTestHandler : HttpMessageHandler
    {
        public int Requests { get; private set; }
        public HttpStatusCode Status { get; set; } = HttpStatusCode.OK;

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Requests++;
            return Task.FromResult(new HttpResponseMessage(Status)
            {
                Content = JsonContent.Create(new Reply([new Receipt("1", "accepted")]))
            });
        }
    }

    private static async Task VerifyServiceSessionConfirmation()
    {
        using var handler = new ServiceConfirmationTestHandler();
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://example.test/dochazka/") };
        http.DefaultRequestHeaders.Add("X-Dochazka", "web");
        char[] password = ['ž', '"', '\\', '\0', 'x'];
        await ServiceSessionConfirmation.Verify(http, new string('a', 64), "správce", password);
        handler.Status = HttpStatusCode.Forbidden;
        try
        {
            await ServiceSessionConfirmation.Verify(http, new string('a', 64), "správce", password);
        }
        catch (InvalidOperationException)
        {
            return;
        }
        throw new InvalidDataException("Denied service confirmation was treated as successful.");
    }

    private sealed class ServiceConfirmationTestHandler : HttpMessageHandler
    {
        public HttpStatusCode Status { get; set; } = HttpStatusCode.OK;

        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync(cancellationToken));
            var fields = body.RootElement;
            if (request.Method != HttpMethod.Post || request.RequestUri?.AbsolutePath != "/dochazka/api/v1/service-session-verify"
                || !request.Headers.GetValues("X-Dochazka").SequenceEqual(["web"])
                || fields.GetProperty("code").GetString() != new string('a', 64)
                || fields.GetProperty("login").GetString() != "správce" || fields.GetProperty("password").GetString() != "ž\"\\")
                throw new InvalidDataException("Unexpected service confirmation request.");
            return new HttpResponseMessage(Status) { Content = JsonContent.Create(new { ok = true }) };
        }
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
