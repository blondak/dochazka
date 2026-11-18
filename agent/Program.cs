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
    private Task? worker;

    public static void Main(string[] args)
    {
        if (args.SequenceEqual(new[] { "--self-test" }))
        {
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
            foreach (var (type, interactive) in new[] { ("2", true), ("11", true), ("3", false), ("5", false), ("7", false) })
            {
                var record = XElement.Parse($"""
                    <Event xmlns="http://schemas.microsoft.com/win/2004/08/events/event"><EventData>
                        <Data Name="LogonType">{type}</Data><Data Name="TargetUserSid">{signal.Sid}</Data>
                        <Data Name="TargetLogonId">0x1b</Data></EventData></Event>
                    """);
                var account = Journal.InteractiveAccount(record);
                if (interactive ? account != (signal.Sid, "0x1b") : account != null)
                    throw new InvalidDataException($"Unexpected classification of logon type {type}.");
            }
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
            return;
        }
        Run(new AttendanceService { ServiceName = "DochazkaAgent" });
    }

    protected override void OnStart(string[] args)
    {
        worker = Task.Run(() => Loop(stop.Token));
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
        stop.Cancel();
        if (worker != null) Task.WhenAny(worker, Task.Delay(TimeSpan.FromSeconds(15))).GetAwaiter().GetResult();
    }

    private async Task Loop(CancellationToken cancel)
    {
        var path = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "DochazkaAgent");
        var configPath = Path.Combine(path, "config.json");
        var config = JsonSerializer.Deserialize<Settings>(File.ReadAllText(configPath)) ?? throw new InvalidDataException();
        var installed = File.GetCreationTimeUtc(configPath);
        var uri = new Uri(config.Server.TrimEnd('/') + "/api/v1/events");
        if (uri.Scheme != Uri.UriSchemeHttps) throw new InvalidDataException("Je vyžadováno HTTPS.");
        using var http = new HttpClient(config.CreateHandler()) { Timeout = TimeSpan.FromSeconds(20) };
        http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", config.Token);
        using var db = new SqliteConnection(new SqliteConnectionStringBuilder { DataSource = Path.Combine(path,"fronta.db") }.ToString());
        db.Open();
        var store = new Journal(db);
        store.Initialize();
        while (!cancel.IsCancellationRequested)
        {
            string? fault = null;
            try { store.Collect("Security", installed, cancel); store.Collect("System", installed, cancel); }
            catch (Exception e) when (!cancel.IsCancellationRequested) { fault = e.Message; store.Warn("Výpadek sběru: " + fault); }
            try
            {
                var batch = store.Batch();
                var warning = store.Warning();
                var resolved = store.ResolvedWarning();
                using var response = await http.PostAsJsonAsync(uri, new { events = batch, warning, resolved_warning = resolved }, cancel);
                response.EnsureSuccessStatusCode();
                var reply = await response.Content.ReadFromJsonAsync<Reply>(cancel) ?? throw new InvalidDataException();
                store.Confirm(batch, reply.Results, warning, resolved);
            }
            catch (OperationCanceledException) when (cancel.IsCancellationRequested) { break; }
            catch (Exception e) { fault = e.Message; }
            if (fault != null) EventLog.WriteEntry("DochazkaAgent", fault, EventLogEntryType.Warning);
            try { await Task.Delay(TimeSpan.FromSeconds(30), cancel); }
            catch (OperationCanceledException) { break; }
        }
    }
}
