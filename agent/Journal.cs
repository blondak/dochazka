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
            string sessionKey = ResolveSessionKey(key, sid, tx);
            using var close = Command("""
                UPDATE sessions SET finish=$p1 WHERE k=$p0 AND sid=$p2 AND finish IS NULL
                AND NOT EXISTS(SELECT 1 FROM desktop_logon WHERE k=$p3 AND sid=$p2 AND closes_session=0)
                """, tx, sessionKey, at, sid, key);
            if (close.ExecuteNonQuery() > 0) Enqueue(new Signal(id, "logout", at, sid, sessionKey), tx);
            return;
        }

        string existingKey = ResolveSessionKey(key, sid, tx);
        if (HasSession(existingKey, sid, tx)) return;
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
        {
            using var first = Command("INSERT INTO desktop_logon VALUES($p0,$p1,$p0,$p2)", tx, key, sid, account.Elevated ? 0 : 1);
            first.ExecuteNonQuery();
            using var alias = Command("INSERT OR IGNORE INTO desktop_logon VALUES($p0,$p1,$p2,$p3)",
                tx, boot + "." + companion, sid, key, account.Elevated ? 1 : 0);
            alias.ExecuteNonQuery();
        }
        Enqueue(new Signal(id, "login", at, sid, key), tx);
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
