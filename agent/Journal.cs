using System.Diagnostics.Eventing.Reader;
using System.Globalization;
using System.Xml.Linq;
using System.Text.Json;
using Microsoft.Data.Sqlite;

namespace Dochazka.Agent;

internal sealed class Journal(SqliteConnection db)
{
    private SqliteCommand Command(string sql, SqliteTransaction? tx = null, params object[] args)
    {
        var cmd = db.CreateCommand();
        cmd.Transaction = tx; cmd.CommandText = sql;
        for (int i=0; i<args.Length; i++) cmd.Parameters.AddWithValue("$p"+i,args[i]);
        return cmd;
    }

    public void Initialize()
    {
        using var cmd=Command("""
            PRAGMA journal_mode=WAL;
            CREATE TABLE IF NOT EXISTS cursor(channel TEXT PRIMARY KEY, seq INTEGER, stamp TEXT);
            CREATE TABLE IF NOT EXISTS queue(id TEXT PRIMARY KEY, payload TEXT);
            CREATE TABLE IF NOT EXISTS sessions(k TEXT PRIMARY KEY,sid TEXT,begin TEXT,finish TEXT);
            CREATE TABLE IF NOT EXISTS desktop_logon(
                k TEXT PRIMARY KEY, sid TEXT NOT NULL, session_key TEXT NOT NULL, closes_session INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS desktop_logon_session ON desktop_logon(session_key);
            CREATE TABLE IF NOT EXISTS windows_session(
                boot TEXT NOT NULL, id INTEGER NOT NULL, sid TEXT NOT NULL, session_key TEXT NOT NULL, observed_at TEXT NOT NULL,
                PRIMARY KEY(boot,id));
            CREATE TABLE IF NOT EXISTS desktop_notice(id INTEGER PRIMARY KEY, windows_id INTEGER NOT NULL, at TEXT NOT NULL, logon INTEGER NOT NULL, account TEXT);
            CREATE TABLE IF NOT EXISTS shutdown_notice(at TEXT PRIMARY KEY);
            CREATE TABLE IF NOT EXISTS health(message TEXT PRIMARY KEY);
            CREATE TABLE IF NOT EXISTS deferred(event_id TEXT PRIMARY KEY, sid TEXT, first_seen INTEGER, retry_at INTEGER);
            CREATE INDEX IF NOT EXISTS deferred_sid ON deferred(sid);
            CREATE TABLE IF NOT EXISTS resolved_health(message TEXT PRIMARY KEY);
            """);
        cmd.ExecuteNonQuery();
    }

    public void Warn(string message)
    {
        using var cmd=Command("INSERT OR IGNORE INTO health VALUES($p0)",null,message);
        cmd.ExecuteNonQuery();
    }
    public string? Warning()
    {
        using var cmd=Command("SELECT message FROM health ORDER BY rowid LIMIT 1");
        return cmd.ExecuteScalar() as string;
    }

    public string? ResolvedWarning()
    {
        using var cmd = Command("SELECT message FROM resolved_health ORDER BY rowid LIMIT 1");
        return cmd.ExecuteScalar() as string;
    }

    private static string UnmappedWarning(string sid) => $"Doplňte SID {sid} v adresáři, stanice čeká na přiřazení.";

    private static EventLogReader Query(string channel,string filter,bool reverse=false)
        => new(new EventLogQuery(channel,PathType.LogName,filter) { ReverseDirection=reverse });

    private static string Stamp(EventRecord record) => record.TimeCreated!.Value.ToUniversalTime().ToString("O");

    public void Collect(string channel,DateTime installed,CancellationToken cancel)
    {
        using var newest = Query(channel,"*",true);
        using var last = newest.ReadEvent();
        if (last?.RecordId is not long high) return;
        long low = 0;
        string? stamp = null;
        using (var cmd=Command("SELECT seq,stamp FROM cursor WHERE channel=$p0",null,channel))
        using (var reader=cmd.ExecuteReader())
        {
            if (reader.Read()) { low=reader.GetInt64(0); stamp=reader.GetString(1); }
        }
        if (stamp != null)
        using (var check=Query(channel,$"*[System[EventRecordID={low}]]"))
        using (var previous=check.ReadEvent())
        {
            if (previous==null || Stamp(previous)!=stamp)
            {
                Warn($"Chybí část protokolu {channel} za {stamp}.");
                using var tx=db.BeginTransaction();
                using var active=Command("SELECT k,sid FROM sessions WHERE finish IS NULL",tx);
                var sessions=new List<(string,string)>();
                using (var reader=active.ExecuteReader()) while(reader.Read()) sessions.Add((reader.GetString(0),reader.GetString(1)));
                foreach(var (key,sid) in sessions)
                    Enqueue(new Signal(Guid.NewGuid().ToString(),"gap",DateTime.UtcNow.ToString("O"),sid,key),tx);
                tx.Commit();
                low=0;
            }
        }
        if (low==high) return;
        string selector=channel=="Security"
            ? "(EventID=4624 or EventID=4634)"
            : "((EventID=42 or EventID=107) and Provider[@Name='Microsoft-Windows-Kernel-Power'])";
        var since = installed.ToString("O", CultureInfo.InvariantCulture);
        using var events=Query(channel,$"*[System[EventRecordID>{low} and EventRecordID<={high} and TimeCreated[@SystemTime>='{since}'] and {selector}]]");
        while (!cancel.IsCancellationRequested)
        {
            using var rec=events.ReadEvent();
            if(rec==null) { Save(channel,high,Stamp(last),null); break; }
            using var tx=db.BeginTransaction();
            if(channel=="Security") Security(rec,tx); else Power(rec,tx);
            Save(channel,rec.RecordId!.Value,Stamp(rec),tx);
            tx.Commit();
        }
    }

    private void Save(string channel,long id,string at,SqliteTransaction? tx)
    {
        using var cmd=Command("INSERT OR REPLACE INTO cursor VALUES($p0,$p1,$p2)",tx,channel,id,at);
        cmd.ExecuteNonQuery();
    }

    internal static string Boot(string at)
    {
        using var reader=Query("System",$"*[System[EventID=12 and Provider[@Name='Microsoft-Windows-Kernel-General'] and TimeCreated[@SystemTime<='{at}']]]",true);
        using var rec=reader.ReadEvent();
        return rec==null ? "unknown" : rec.TimeCreated!.Value.ToUniversalTime().Ticks.ToString(CultureInfo.InvariantCulture);
    }

    internal sealed record DesktopAccount(string Sid, string LogonId, string? LinkedLogonId, bool Elevated);

    internal static DesktopAccount? InteractiveAccount(int eventId, XElement xml)
    {
        if (eventId is not (4624 or 4634)) return null;
        var ns=xml.Name.Namespace;
        var fields=xml.Descendants(ns+"Data").Where(n=>n.Attribute("Name")!=null)
            .ToDictionary(n=>n.Attribute("Name")!.Value,n=>n.Value);
        if(fields.GetValueOrDefault("LogonType") is not ("2" or "11")) return null;
        var sid=fields.GetValueOrDefault("TargetUserSid") ?? "";
        var logonId=NormalizeLogonId(fields.GetValueOrDefault("TargetLogonId"));
        if(!sid.StartsWith("S-1-5-21-",StringComparison.Ordinal) || logonId == null) return null;
        if (eventId == 4624)
        {
            if (!string.Equals(fields.GetValueOrDefault("LogonProcessName")?.Trim(), "User32", StringComparison.OrdinalIgnoreCase))
                return null;
            var process = fields.GetValueOrDefault("ProcessName")?.Split('\\', '/').LastOrDefault();
            if (!string.Equals(process, "winlogon.exe", StringComparison.OrdinalIgnoreCase)
                && !string.Equals(process, "svchost.exe", StringComparison.OrdinalIgnoreCase))
                return null;
        }
        return new DesktopAccount(sid, logonId, NormalizeLogonId(fields.GetValueOrDefault("TargetLinkedLogonId")),
            fields.GetValueOrDefault("ElevatedToken") == "%%1842");
    }

    private static string? NormalizeLogonId(string? value)
    {
        if (value == null || !value.StartsWith("0x", StringComparison.OrdinalIgnoreCase)
            || !ulong.TryParse(value.AsSpan(2), NumberStyles.HexNumber, CultureInfo.InvariantCulture, out var number) || number == 0)
            return null;
        return "0x" + number.ToString("x", CultureInfo.InvariantCulture);
    }

    internal string ResolveSessionKey(string key, string sid, SqliteTransaction? tx = null)
    {
        using var command = Command("SELECT session_key FROM desktop_logon WHERE k=$p0 AND sid=$p1", tx, key, sid);
        return command.ExecuteScalar() as string ?? key;
    }

    private void Security(EventRecord rec,SqliteTransaction tx)
    {
        var account = InteractiveAccount(rec.Id, XElement.Parse(rec.ToXml()));
        if (account == null) return;
        var at=Stamp(rec);
        var id=$"{rec.RecordId}.{rec.TimeCreated!.Value.ToUniversalTime().Ticks}";
        RecordSecurity(rec.Id, account, Boot(at), id, at, tx);
    }

    internal void RecordSecurity(int eventId, DesktopAccount account, string boot, string id, string at, SqliteTransaction tx)
    {
        string sid = account.Sid;
        string key = boot + "." + account.LogonId;
        if (boot == "unknown")
        {
            if (eventId == 4624) Enqueue(new Signal(id, "gap", at, sid, key), tx);
            return;
        }
        if (eventId == 4634)
        {
            using var secondary = Command("SELECT 1 FROM desktop_logon WHERE k=$p0 AND sid=$p1 AND closes_session=0", tx, key, sid);
            if (secondary.ExecuteScalar() == null)
                CloseSession(ResolveSessionKey(key, sid, tx), sid, id, at, tx);
            return;
        }

        string existingKey = ResolveSessionKey(key, sid, tx);
        if (HasSession(existingKey, sid, tx))
        {
            // A later audit record can identify the second token of an already known session.
            if (account.LinkedLogonId is { } otherLogon && otherLogon != account.LogonId)
            {
                string companionKey = boot + "." + otherLogon;
                if (!HasSession(companionKey, sid, tx))
                    RememberDesktopPair(account, key, companionKey, existingKey, tx);
            }
            return;
        }
        if (account.LinkedLogonId is { } linked && linked != account.LogonId)
        {
            string linkedKey = boot + "." + linked;
            string sessionKey = ResolveSessionKey(linkedKey, sid, tx);
            if (HasSession(sessionKey, sid, tx))
            {
                // Keep the first key sent to the server. The desktop uses the filtered token
                // at sign-out when both the standard and elevated logons were recorded.
                using var keepLinked = Command("INSERT OR IGNORE INTO desktop_logon VALUES($p0,$p1,$p2,1)", tx, linkedKey, sid, sessionKey);
                keepLinked.ExecuteNonQuery();
                if (!account.Elevated)
                {
                    using var preferDesktop = Command("UPDATE desktop_logon SET closes_session=0 WHERE session_key=$p0 AND sid=$p1", tx, sessionKey, sid);
                    preferDesktop.ExecuteNonQuery();
                }
                using var alias = Command("INSERT INTO desktop_logon VALUES($p0,$p1,$p2,$p3)", tx, key, sid, sessionKey, account.Elevated ? 0 : 1);
                alias.ExecuteNonQuery();
                return;
            }
        }
        using var open = Command("INSERT OR IGNORE INTO sessions(k,sid,begin) VALUES($p0,$p1,$p2)", tx, key, sid, at);
        if (open.ExecuteNonQuery() == 0) return;
        if (account.LinkedLogonId is { } companion && companion != account.LogonId)
            RememberDesktopPair(account, key, boot + "." + companion, key, tx);
        Enqueue(new Signal(id, "login", at, sid, key), tx);
    }

    private void RememberDesktopPair(DesktopAccount account, string key, string companion, string sessionKey, SqliteTransaction tx)
    {
        using var aliases = Command("INSERT OR IGNORE INTO desktop_logon VALUES($p0,$p1,$p2,$p3),($p4,$p1,$p2,$p5)",
            tx, key, account.Sid, sessionKey, account.Elevated ? 0 : 1, companion, account.Elevated ? 1 : 0);
        aliases.ExecuteNonQuery();
    }

    internal void SaveNotice(int sessionId, string at, bool logon, DesktopAccount? account)
    {
        using var insert = Command("INSERT INTO desktop_notice(windows_id,at,logon,account) VALUES($p0,$p1,$p2,$p3)",
            null, sessionId, at, logon, account == null ? (object)DBNull.Value : JsonSerializer.Serialize(account));
        insert.ExecuteNonQuery();
    }

    internal void ReplayNotices(Func<string, string> resolveBoot, CancellationToken cancel)
    {
        long after = 0;
        while (!cancel.IsCancellationRequested)
        {
            int sessionId;
            string at;
            bool logon;
            DesktopAccount? account;
            using (var next = Command("SELECT id,windows_id,at,logon,account FROM desktop_notice WHERE id>$p0 ORDER BY id LIMIT 1", null, after))
            using (var reader = next.ExecuteReader())
            {
                if (!reader.Read()) return;
                after = reader.GetInt64(0);
                sessionId = reader.GetInt32(1);
                at = reader.GetString(2);
                logon = reader.GetBoolean(3);
                account = reader.IsDBNull(4) ? null : JsonSerializer.Deserialize<DesktopAccount>(reader.GetString(4));
            }
            // Resolve at capture time so a service or computer restart cannot bind an old notice to a new boot.
            string boot = resolveBoot(at);
            if (boot == "unknown") continue;
            using var tx = db.BeginTransaction();
            if (logon) RecordWindowsLogon(sessionId, account, boot, at, tx);
            else RecordWindowsLogoff(sessionId, boot, at, tx);
            using var done = Command("DELETE FROM desktop_notice WHERE id=$p0", tx, after);
            done.ExecuteNonQuery();
            tx.Commit();
        }
    }

    internal void RecordWindowsLogon(int windowsId, DesktopAccount? account, string boot, string at, SqliteTransaction tx, bool observed = true)
    {
        if (account == null)
        {
            // Windows may reuse the session number for another user.
            using var forget = Command("DELETE FROM windows_session WHERE boot=$p0 AND id=$p1 AND observed_at<=$p2",
                tx, boot, windowsId, at);
            forget.ExecuteNonQuery();
        }
        else
        {
            string key = boot + "." + account.LogonId;
            if (observed)
                RecordSecurity(4624, account, boot, "wts.login." + key, at, tx);
            else if (!HasSession(ResolveSessionKey(key, account.Sid, tx), account.Sid, tx) && account.LinkedLogonId is { } linked)
                key = boot + "." + linked;

            string sessionKey = ResolveSessionKey(key, account.Sid, tx);
            using var remember = Command("""
                INSERT INTO windows_session (boot,id,sid,session_key,observed_at)
                SELECT $p0,$p1,sid,k,$p2 FROM sessions WHERE k=$p3 AND sid=$p4 AND finish IS NULL
                ON CONFLICT(boot,id) DO UPDATE SET sid=excluded.sid, session_key=excluded.session_key, observed_at=excluded.observed_at
                WHERE windows_session.observed_at <= excluded.observed_at
                """, tx, boot, windowsId, at, sessionKey, account.Sid);
            remember.ExecuteNonQuery();
        }
    }

    internal void RecordWindowsLogoff(int windowsId, string boot, string at, SqliteTransaction tx)
    {
        string? sessionKey = null, sid = null;
        using (var lookup = Command("SELECT session_key,sid FROM windows_session WHERE boot=$p0 AND id=$p1 AND observed_at<=$p2",
            tx, boot, windowsId, at))
        using (var reader = lookup.ExecuteReader())
        {
            if (reader.Read()) { sessionKey = reader.GetString(0); sid = reader.GetString(1); }
        }
        if (sessionKey != null && sid != null)
            CloseSession(sessionKey, sid, "wts.logout." + sessionKey, at, tx);
    }

    internal void SaveShutdown(string at)
    {
        using var insert = Command("INSERT OR IGNORE INTO shutdown_notice VALUES($p0)", null, at);
        insert.ExecuteNonQuery();
    }

    internal void ReplayShutdowns(Func<string, string> resolveBoot, CancellationToken cancel)
    {
        var notices = new List<string>();
        using (var command = Command("SELECT at FROM shutdown_notice ORDER BY at"))
        using (var reader = command.ExecuteReader())
            while (reader.Read()) notices.Add(reader.GetString(0));

        foreach (string at in notices)
        {
            if (cancel.IsCancellationRequested) return;
            string boot = resolveBoot(at);
            if (boot == "unknown") continue;
            using var tx = db.BeginTransaction();
            var sessions = new List<(string Key, string Sid)>();
            // A missed logoff can leave an old session open in the journal. Only infer an
            // end for consoles observed during the last two minutes of this boot.
            var recent = DateTime.ParseExact(at, "O", CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind)
                .AddMinutes(-2).ToString("O", CultureInfo.InvariantCulture);
            using (var command = Command("""
                SELECT session_key,sid FROM windows_session
                WHERE boot=$p0 AND observed_at BETWEEN $p1 AND $p2
                """, tx, boot, recent, at))
            using (var reader = command.ExecuteReader())
                while (reader.Read()) sessions.Add((reader.GetString(0), reader.GetString(1)));
            foreach (var (key, sid) in sessions)
                CloseSession(key, sid, "shutdown.logout." + key, at, tx);
            using var done = Command("DELETE FROM shutdown_notice WHERE at=$p0", tx, at);
            done.ExecuteNonQuery();
            tx.Commit();
        }
    }

    private void CloseSession(string sessionKey, string sid, string id, string at, SqliteTransaction tx)
    {
        using var close = Command("UPDATE sessions SET finish=$p0 WHERE k=$p1 AND sid=$p2 AND finish IS NULL AND begin<=$p0",
            tx, at, sessionKey, sid);
        if (close.ExecuteNonQuery() == 0) return;
        Enqueue(new Signal(id, "logout", at, sid, sessionKey), tx);
        using var forget = Command("DELETE FROM windows_session WHERE session_key=$p0 AND sid=$p1", tx, sessionKey, sid);
        forget.ExecuteNonQuery();
    }

    private bool HasSession(string key, string sid, SqliteTransaction tx)
    {
        using var command = Command("SELECT 1 FROM sessions WHERE k=$p0 AND sid=$p1", tx, key, sid);
        return command.ExecuteScalar() != null;
    }

    private void Power(EventRecord rec,SqliteTransaction tx)
    {
        string at=Stamp(rec);
        string boot=Boot(at);
        using var cmd=Command("SELECT k,sid FROM sessions WHERE k LIKE $p1 AND begin<=$p0 AND (finish IS NULL OR finish>$p0)",tx,at,boot+".%");
        var sessions=new List<(string,string)>();
        using(var reader=cmd.ExecuteReader()) while(reader.Read()) sessions.Add((reader.GetString(0),reader.GetString(1)));
        var kind = rec.Id == 42 ? "sleep" : "resume";
        foreach(var (key,sid) in sessions) Enqueue(new Signal($"{kind}.{rec.RecordId}.{key}",kind,at,sid,key),tx);
    }

    private void Enqueue(Signal e,SqliteTransaction tx)
    {
        using var cmd=Command("INSERT OR IGNORE INTO queue VALUES($p0,$p1)",tx,e.Id,JsonSerializer.Serialize(e));
        cmd.ExecuteNonQuery();
    }

    public List<Signal> Batch()
    {
        using var cmd=Command("""
            SELECT q.payload FROM queue q LEFT JOIN deferred d ON d.event_id=q.id
            WHERE d.event_id IS NULL OR d.retry_at <= CAST(strftime('%s','now') AS INTEGER)
            ORDER BY q.rowid LIMIT 250
            """);
        using var reader=cmd.ExecuteReader();
        var batch=new List<Signal>();
        while(reader.Read()) batch.Add(JsonSerializer.Deserialize<Signal>(reader.GetString(0))!);
        return batch;
    }

    public void Confirm(List<Signal> batch,Receipt[] results,string? warning,string? resolved = null)
    {
        using var tx=db.BeginTransaction();
        if (warning != null) { using var ack = Command("DELETE FROM health WHERE message=$p0", tx, warning); ack.ExecuteNonQuery(); }
        if (resolved != null) { using var ack = Command("DELETE FROM resolved_health WHERE message=$p0", tx, resolved); ack.ExecuteNonQuery(); }
        var sent=batch.ToDictionary(x=>x.Id);
        foreach(var receipt in results)
        {
            if(!sent.TryGetValue(receipt.Id, out var signal)) continue;
            if (receipt.Status == "unmapped")
            {
                long now = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
                using var defer = Command("""
                    INSERT INTO deferred VALUES($p0,$p1,$p2,$p3)
                    ON CONFLICT(event_id) DO UPDATE SET retry_at=excluded.retry_at
                    """, tx, signal.Id, signal.Sid, now, now + 3600);
                defer.ExecuteNonQuery();
                using var first = Command("SELECT first_seen FROM deferred WHERE event_id=$p0", tx, signal.Id);
                bool expired = now - (long)first.ExecuteScalar()! >= 30L * 86400;
                var message = expired ? $"Po 30 dnech vypršela lhůta pro nepřiřazený účet {signal.Sid}."
                    : UnmappedWarning(signal.Sid);
                using var reopen = Command("DELETE FROM resolved_health WHERE message=$p0", tx, message);
                reopen.ExecuteNonQuery();
                using var warn = Command("INSERT OR IGNORE INTO health VALUES($p0)", tx, message);
                warn.ExecuteNonQuery();
                if (!expired) continue;
            }
            else if(receipt.Status is "invalid" or "conflict")
            {
                using var fault=Command("INSERT OR IGNORE INTO health VALUES($p0)",tx,$"Odmítnutý záznam {receipt.Id}: {receipt.Status}");
                fault.ExecuteNonQuery();
            }
            else if(receipt.Status is not ("accepted" or "duplicate" or "ignored")) continue;
            using var clear = Command("DELETE FROM deferred WHERE event_id=$p0", tx, receipt.Id);
            if (clear.ExecuteNonQuery() > 0)
            {
                using var remaining = Command("SELECT 1 FROM deferred WHERE sid=$p0 LIMIT 1", tx, signal.Sid);
                if (remaining.ExecuteScalar() == null)
                {
                    var message = UnmappedWarning(signal.Sid);
                    using var pending = Command("DELETE FROM health WHERE message=$p0", tx, message);
                    pending.ExecuteNonQuery();
                    using var mark = Command("INSERT OR IGNORE INTO resolved_health VALUES($p0)", tx, message);
                    mark.ExecuteNonQuery();
                }
            }
            using var cmd=Command("DELETE FROM queue WHERE id=$p0",tx,receipt.Id);
            cmd.ExecuteNonQuery();
        }
        tx.Commit();
    }
}
