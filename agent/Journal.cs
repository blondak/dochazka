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

    private static string Boot(string at)
    {
        using var reader=Query("System",$"*[System[EventID=12 and Provider[@Name='Microsoft-Windows-Kernel-General'] and TimeCreated[@SystemTime<='{at}']]]",true);
        using var rec=reader.ReadEvent();
        return rec==null ? "unknown" : rec.TimeCreated!.Value.ToUniversalTime().Ticks.ToString(CultureInfo.InvariantCulture);
    }

    internal static (string Sid, string LogonId)? InteractiveAccount(XElement xml)
    {
        var ns=xml.Name.Namespace;
        var fields=xml.Descendants(ns+"Data").Where(n=>n.Attribute("Name")!=null)
            .ToDictionary(n=>n.Attribute("Name")!.Value,n=>n.Value);
        if(fields.GetValueOrDefault("LogonType") is not ("2" or "11")) return null;
        var sid=fields.GetValueOrDefault("TargetUserSid") ?? "";
        var logonId=fields.GetValueOrDefault("TargetLogonId");
        if(!sid.StartsWith("S-1-5-21-",StringComparison.Ordinal) || string.IsNullOrEmpty(logonId)) return null;
        return (sid, logonId);
    }

    private void Security(EventRecord rec,SqliteTransaction tx)
    {
        var account = InteractiveAccount(XElement.Parse(rec.ToXml()));
        if (account == null) return;
        var (sid, logonId) = account.Value;
        var at=Stamp(rec);
        var boot=Boot(at);
        var key=boot+"."+logonId;
        var id=$"{rec.RecordId}.{rec.TimeCreated!.Value.ToUniversalTime().Ticks}";
        if(boot=="unknown") { Enqueue(new Signal(id,"gap",at,sid,key),tx); return; }
        bool login=rec.Id==4624;
        using var cmd=login ? Command("INSERT OR REPLACE INTO sessions(k,sid,begin) VALUES($p0,$p1,$p2)",tx,key,sid,at)
            : Command("UPDATE sessions SET finish=$p1 WHERE k=$p0",tx,key,at);
        cmd.ExecuteNonQuery();
        Enqueue(new Signal(id,login?"login":"logout",at,sid,key),tx);
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
