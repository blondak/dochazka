"""Exercise the actual HTTP endpoints against an isolated schema in a test PostgreSQL database."""
from contextlib import contextmanager
import http.client
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
schema = "attendance_test_" + secrets.token_hex(8)
env = dict(os.environ, PGOPTIONS="-c search_path=" + schema)
pw = secrets.token_hex(12)

def sql(statement):
    subprocess.run(["psql", "-X", "-v", "ON_ERROR_STOP=1", "-c", statement], env=env, check=True, stdout=subprocess.DEVNULL)

@contextmanager
def hold_database_locks(statement):
    with subprocess.Popen(["psql", "-XqAt", "-v", "ON_ERROR_STOP=1"], env=env, stdin=subprocess.PIPE,
                          stdout=subprocess.PIPE, text=True) as db:
        try:
            db.stdin.write("BEGIN;\n" + statement + "\n\\echo ready\n")
            db.stdin.flush()
            while (line := db.stdout.readline()) and line.strip() != "ready":
                pass
            assert line, "Lock transaction failed"
            yield
        finally:
            db.stdin.close()  # Closing this connection releases its transaction locks.

def php(*args, data=None):
    subprocess.run([PHP, *args], input=data, cwd=ROOT, env=env, text=True, check=True)

class Client:
    def __init__(self, connect):
        self.connect = connect
        self.cookie = ""

    def call(self, op, data=None, status=200, token=None, web=True):
        conn = self.connect()
        headers = {"Content-Type": "application/json", "Cookie": self.cookie}
        if web:
            headers["X-Dochazka"] = "web"
        if token:
            headers["Authorization"] = "Bearer " + token
        conn.request("POST", "/api/v1/" + op, json.dumps(data or {}), headers)
        response = conn.getresponse()
        for header, value in response.getheaders():
            if header.lower() == "set-cookie" and value.startswith("PHPSESSID="):
                if op == "login":
                    attributes = {part.strip().lower() for part in value.split(";")[1:]}
                    assert {"secure", "httponly", "samesite=strict"} <= attributes, attributes
                self.cookie = value.split(";", 1)[0]
        raw = response.read()
        conn.close()
        assert response.status == status, (op, response.status, raw[:1000])
        return json.loads(raw)

def exercise(connect, php_command, check_locks=False):
    def fill(first, last, *options):
        return subprocess.check_output([*php_command, "bin/console", "attendance:fill-holidays",
                                        "--from=" + first, "--to=" + last, *options], cwd=ROOT, env=env, text=True)


    anonymous = Client(connect)
    anonymous.call("sheet", status=401)
    anonymous.call("events", {"events": []}, 401, web=False)
    anonymous.call("domain-enroll", status=403, web=False)
    admin = Client(connect)
    admin.call("login", {"login":"root","password":pw})
    admin.call("domain-enroll", status=403)
    clients = {}
    sid = "S-1-5-21-1-2-3-44"
    zone = ZoneInfo("Europe/Prague")
    previous_month = datetime.now(zone).replace(day=1) - timedelta(days=1)
    month = previous_month.strftime("%Y-%m")
    units = {name: admin.call("org-unit", {"name": name, "parent_id": None})["id"] for name in ("A", "B")}
    branch = admin.call("org-unit", {"name": "Provoz", "parent_id": units["A"]})["id"]
    warehouse = admin.call("org-unit", {"name": "Sklad", "parent_id": branch})["id"]
    admin.call("org-unit", {"name": "Sklad", "parent_id": branch}, 409)
    empty = admin.call("org-unit", {"name": "Sklad", "parent_id": units["B"]})
    admin.call("org-unit-delete", empty)
    admin.call("org-unit", {"id": branch, "name": "Provoz", "parent_id": warehouse}, 409)
    admin.call("org-unit-delete", {"id": branch}, 409)
    for role,team in [("employee","A"),("manager","A"),("payroll","B"),("manager","B"),("hr","B")]:
        login = role + team
        uid = admin.call("employee", {"name":login,"login":login,"number":login,"org_unit_id":warehouse if role=="employee" else units[team],"roles":[role],"attendance_enabled":role=="employee",
            "starts":month+"-01","sid":sid if role=="employee" else ""})["id"]
        code = admin.call("invite", {"id":uid})["code"]
        c = Client(connect)
        c.call("activate", {"code":code,"password":pw})
        c.call("login", {"login":login,"password":pw})
        clients[login] = (c, uid)
    emp, uid = clients["employeeA"]
    manager = clients["managerA"][0]
    payroll = clients["payrollB"][0]
    scope = {"employee":uid,"month":month}
    hr = clients["hrB"][0]
    assert hr.call("org-units")
    employee_info = next(p for p in hr.call("people") if p["id"] == uid)
    assert "sid" not in employee_info and "login" not in employee_info
    hr.call("employee",dict(employee_info, name="employeeA", roles=["admin"], login="unused"),403)
    hr.call("employee",employee_info)
    hr_employee = hr.call("employee", {"name":"Nový zaměstnanec", "org_unit_id":warehouse,
        "attendance_enabled":False, "starts":month+"-01"})
    created = next(p for p in admin.call("people") if p["id"] == hr_employee["id"])
    assert created["roles"] == ["employee"] and created["login"].startswith("osoba-")
    assert next(p for p in admin.call("people") if p["id"] == uid)["roles"] == ["employee"]
    for op in ["devices","invite","employee-archive","approve","close"]:
        hr.call(op,scope,403)
    assert hr.call("work-models") == []
    assert hr.call("attendance-overview",dict(scope,team=False))["people"][0]["planned"] is None
    manager.call("attendance-overview",dict(scope,team=False),403)
    assert clients["managerB"][0].call("attendance-overview",dict(scope,team=True))["people"] == []
    new_password = secrets.token_hex(12)
    anonymous.call("change-password", {"current_password": pw, "new_password": new_password}, 401)
    emp.call("change-password", {"current_password": "wrong", "new_password": new_password}, 403)
    emp.call("change-password", {"current_password": pw, "new_password": "short"}, 422)
    emp.call("change-password", {"current_password": pw, "new_password": new_password})
    another_session = Client(connect)
    another_session.call("login", {"login": "employeeA", "password": pw}, 401)
    another_session.call("login", {"login": "employeeA", "password": new_password})
    emp.call("sheet", scope, 403, web=False)
    emp.call("approve", scope, 403)
    admin.call("close", scope, 403)
    clients["managerB"][0].call("sheet",scope,403)
    registration = admin.call("register", {"name":"PC1"})
    token = anonymous.call("enroll", registration, web=False)["token"]
    anonymous.call("enroll", registration, 401, web=False)
    anonymous.call("sheet", scope, 401, token=token)
    assert admin.call("devices")[0]["agent_version"] is None
    if check_locks:
        with hold_database_locks("LOCK TABLE export IN SHARE ROW EXCLUSIVE MODE; SELECT pg_advisory_xact_lock_shared(718312);"):
            assert anonymous.call("events", {"events":[]}, token=token, web=False)["results"] == []
            assert admin.call("devices")
    emp.call("devices", status=403)
    events = [{"id":str(i),"session":"boot.1","sid":sid,"kind":kind,
               "at":datetime.fromisoformat(month+"-03T"+hour+":00").replace(tzinfo=zone).isoformat()}
              for i,(kind,hour) in enumerate([("logout","16"),("login","08")])]
    for expected in ["accepted","duplicate"]:
        results = anonymous.call("events", {"events": events}, token=token, web=False)["results"]
        assert [r["status"] for r in results] == [expected,expected]
    for version, expected in [("0.0.6", "0.0.6"), (None, "0.0.6"), ("<script>", "0.0.6"), ("0.0.7-rc.1", "0.0.7-rc.1")]:
        heartbeat = {"events": []}
        if version is not None:
            heartbeat["agent_version"] = version
        assert anonymous.call("events", heartbeat, token=token, web=False)["results"] == []
        assert admin.call("devices")[0]["agent_version"] == expected
    service = {"session": "638990000000000000.0x1b", "sid": sid}
    events_for_service = [
        dict(service, id="tech-login", kind="login", at=month+"-04T18:00:00+01:00"),
        dict(service, id="tech-logout", kind="logout", at=month+"-04T19:00:00+01:00"),
    ]
    anonymous.call("service-session-link", service, 401, web=False)
    anonymous.call("service-session-link", service, 409, token=token, web=False)
    anonymous.call("events", {"events": events_for_service[:1]}, token=token, web=False)
    old_link = anonymous.call("service-session-link", service, token=token, web=False)
    link = anonymous.call("service-session-link", service, token=token, web=False)
    technician = Client(connect)
    technician.call("service-session-details", old_link, 404)
    assert technician.call("service-session-details", link)["station"] == "PC1"
    technician.call("service-session-verify", dict(link, login="root", password="wrong"), 401)
    technician.call("service-session-verify", dict(link, login="employeeA", password=new_password), 403)
    technician.call("service-session-verify", dict(link, login="root", password=pw))
    technician.call("me", status=401)
    technician.call("service-session-verify", dict(link, login="root", password=pw), 404)
    if os.getenv("WEBAUTHN_BROWSER") == "1":
        passkey_link = anonymous.call("service-session-link", service, token=token, web=False)
        subprocess.run(
            ["node", "web/tests/authenticator.mjs"], cwd=ROOT, text=True, check=True,
            input=json.dumps(dict(login="root", password=pw, origin=f"http://localhost:{connect().port}",
                serviceCode=passkey_link["code"])),
        )
        technician.call("service-session-details", passkey_link, 404)
    anonymous.call("events", {"events": events_for_service[1:]}, token=token, web=False)
    marked = emp.call("sheet", scope)
    service_row = next(row for row in marked["sessions"] if row["session"] == service["session"])
    assert service_row["service"] and service_row["to"] and marked["seconds"] == 8 * 3600
    change = {"employee":uid, "device":service_row["device"], "session":service["session"], "service":False}
    emp.call("service-session-change", change, 403)
    manager.call("service-session-change", change, 403)
    admin.call("service-session-change", change)
    assert emp.call("sheet", scope)["seconds"] == 9 * 3600
    admin.call("service-session-change", dict(change, service=True))
    assert emp.call("sheet", scope)["seconds"] == 8 * 3600
    technician.call("service-session-link", service, 409, token=token, web=False)

    now = datetime.now(zone).replace(microsecond=0)
    day_start = now.replace(hour=0, minute=0, second=0)
    logout = min(now, day_start + timedelta(minutes=45))
    return_at = min(now, day_start + timedelta(hours=1))
    today_seconds = int((logout - day_start).total_seconds())
    anonymous.call("daily-attendance", status=401)
    emp.call("daily-attendance", status=403)
    admin.call("daily-attendance", status=403)
    assert clients["managerB"][0].call("daily-attendance")["people"] == []
    past_day = month+"-03"
    past_daily = manager.call("daily-attendance", {"day": past_day})
    assert past_daily["day"] == past_day
    assert past_daily["people"] == [{"id": uid, "name":"employeeA", "status":"finished", "worked_seconds":8*3600,
        "from": datetime.fromisoformat(past_day+"T08:00:00").replace(tzinfo=zone).isoformat(),
        "to": datetime.fromisoformat(past_day+"T16:00:00").replace(tzinfo=zone).isoformat()}]
    service_daily = manager.call("daily-attendance", {"day": month+"-04"})
    assert service_daily["people"][0]["status"] == "absent"
    for invalid in (None, "2026-02-30", (day_start.date()+timedelta(days=1)).isoformat()):
        manager.call("daily-attendance", {"day": invalid}, 422)

    def employee_today(status, seconds=None, begins=None, ends=None):
        daily = manager.call("daily-attendance")
        assert daily["day"] == day_start.date().isoformat()
        assert daily["people"] == [{"id": uid, "name": "employeeA", "status": status, "worked_seconds": seconds,
                                    "from": begins, "to": ends}]

    employee_today("absent")
    current_events = [
        {"id":"day-login", "kind":"login", "session":"day.1", "sid":sid, "at":max(day_start-timedelta(hours=1), day_start.replace(day=1)).isoformat()},
        {"id":"day-logout", "kind":"logout", "session":"day.1", "sid":sid, "at":logout.isoformat()},
        {"id":"day-return", "kind":"login", "session":"day.2", "sid":sid, "at":return_at.isoformat()},
    ]
    anonymous.call("events", {"events": current_events[:2]}, token=token, web=False)
    employee_today("finished", today_seconds, day_start.isoformat(), logout.isoformat())
    overnight_login = current_events[0]["at"]
    if overnight_login[:10] != day_start.date().isoformat():
        overnight = manager.call("daily-attendance", {"day": overnight_login[:10]})
        assert overnight["people"] == [{"id":uid, "name":"employeeA", "status":"present", "worked_seconds":None,
                                       "from":overnight_login, "to":None}]
    anonymous.call("events", {"events": current_events[2:]}, token=token, web=False)
    employee_today("present", today_seconds, day_start.isoformat())
    admin.call("service-session-change", dict(change, session="day.2", service=True))
    employee_today("finished", today_seconds, day_start.isoformat(), logout.isoformat())
    anonymous.call("events", {"events": [dict(current_events[2], id="day-return-logout", kind="logout")]}, token=token, web=False)
    initial_model = admin.call("work-model", {"name": "Běžná směna", "effective_on": month+"-01",
        "week": [{"start": "08:00", "end": "16:00"}] * 7})["id"]
    profile = next(p for p in admin.call("people") if p["id"] == uid)
    assignment = {"model": initial_model, "effective_on": month+"-01", "reason":"Test úvazku"}
    preview = admin.call("work-model-change-preview", dict(assignment, employee=uid))
    admin.call("employee", dict(profile, work_model=dict(assignment, preview_token=preview["token"])))
    assert admin.call("employee-model-choices", {"employee":uid})["assignments"][0]["model"] == initial_model
    visit = {"kind": "LEKAR", "from": month+"-03T10:00", "to": month+"-03T11:00", "note":"Poznámka pro vedoucího"}
    photo = "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgGB4AAADIAAH4XVuUAAAAAElFTkSuQmCC"
    emp.call("request", dict(visit, photo=photo), 422)
    req = emp.call("request", visit)
    inbox = manager.call("attendance-overview",dict(scope, team=True))
    assert any(task.get("request") == req["id"] for task in inbox["tasks"])
    assert not clients["managerB"][0].call("attendance-overview",dict(scope,team=True))["tasks"]
    assert manager.call("team-coverage",req) == []
    hr.call("team-coverage",req,403)
    upload = dict(req, photo=photo)
    emp.call("request-proof-upload", upload, 409)
    manager.call("request-proof", req, 404)
    payroll.call("close", scope, 409)
    manager.call("decide", dict(req, status="approved"))

    visit_day = manager.call("daily-attendance", {"day": month+"-03"})
    assert next(p for p in visit_day["people"] if p["id"] == uid)["approved_absences"] == ["Lékař"]
    sheet = manager.call("sheet", scope)
    assert sheet["seconds"] == 28800 and len(sheet["rows"]) == 1 and sheet["missing_actual"] == 1
    assert sheet["pending"] == 0 and sheet["missing_proofs"] == 1
    assert "T" in sheet["requests"][0]["begins"] and "T" in sheet["requests"][0]["ends"]
    assert sheet["approval_blockers"] == ["Zaměstnanec musí přiložit povinné doklady.", "Doplňte skutečný čas žádostí, doklad nebo rozvrh."]
    assert [task["reason"] for task in sheet["approval_tasks"]] == sheet["approval_blockers"]
    assert sheet["approval_tasks"][0]["owner"] == "Zaměstnanec"
    assert manager.call("approve", dict(scope, fingerprint=sheet["fingerprint"]), 409)["error"] == sheet["approval_blockers"][0]
    payroll.call("close", scope, 409)
    manager.call("request-proof-upload", upload, 404)
    payroll.call("request-proof-upload", upload, 404)
    emp.call("request-proof-upload", dict(req, photo="bnVsbA=="), 422)

    future = (datetime.now(ZoneInfo("Europe/Prague")) + timedelta(days=1)).replace(hour=10, minute=0)
    upcoming = emp.call("request", {
        "kind": "LEKAR",
        "from": future.isoformat(timespec="minutes")[:16],
        "to": (future + timedelta(hours=1)).isoformat(timespec="minutes")[:16],
    })
    manager.call("decide", dict(upcoming, status="approved"))
    emp.call("request-proof-upload", dict(upcoming, photo=photo), 409)
    manager.call("decide", dict(upcoming, status="rejected", reason="Test"))
    emp.call("request-proof-upload", dict(upcoming, photo=photo), 409)

    emp.call("request-actual", dict(req, **{field:visit[field] for field in ("from", "to")}, photo=photo))
    emp.call("request-proof-upload", upload, 409)
    assert manager.call("request-proof",req)["jpeg"].startswith("/9j/")
    other_manager = clients["managerB"][0]
    manager.call("org-units", status=403)
    admin.call("org-unit-delete", {"id": warehouse}, 409)
    admin.call("org-unit", {"id": branch, "name": "Provoz", "parent_id": units["B"]})
    assert uid not in [p["id"] for p in manager.call("daily-attendance")["people"]]
    assert uid in [p["id"] for p in other_manager.call("daily-attendance")["people"]]
    manager.call("sheet", scope, 403)
    manager.call("request-proof", req, 404)
    assert other_manager.call("request-proof", req)["jpeg"].startswith("/9j/")
    assert next(p for p in other_manager.call("people") if p["id"] == uid)["org_unit_path"] == "B › Provoz › Sklad"
    admin.call("org-unit", {"id": branch, "name": "Provoz", "parent_id": units["A"]})
    assert emp.call("request-proof",req)["jpeg"].startswith("/9j/")
    assert all("note" not in r and "reason" not in r for r in admin.call("sheet", scope)["requests"])
    admin.call("request-proof",req,404)
    admin.call("approve", dict(scope, fingerprint=""), 403)
    payroll.call("request-proof",req,404)
    clients["managerB"][0].call("request-proof",req,404)
    sheet = manager.call("sheet", scope)
    assert sheet["missing_proofs"] == 0
    assert next(r for r in sheet["requests"] if r["id"] == req["id"])["note"] == visit["note"]
    assert all("note" not in r and "reason" not in r for r in payroll.call("sheet", scope)["requests"])
    assert sheet["fund"]["review"] and sheet["approval_note_required"]
    manager.call("approve", dict(scope, fingerprint=sheet["fingerprint"]),422)
    manager.call("approve",dict(scope,fingerprint=sheet["fingerprint"],note="Zkontrolovány plánované směny."))
    version=payroll.call("close",scope)["versions"][0]["version"]
    if check_locks:
        with hold_database_locks("SELECT pg_advisory_xact_lock(718312);"):
            assert payroll.call("close", scope)["versions"][0]["version"] == version

    csv=payroll.call("download",dict(scope,version=version))["csv"]
    assert "T" in payroll.call("payroll", scope)["versions"][0]["created"]
    assert "LEKAR" in csv and len(csv.splitlines())==4
    # A late interval must invalidate approval and preserve the previous export.
    for e in events:
        e.update(id=e["id"]+"late",session="boot.2",at=e["at"].replace("-03T","-04T"))
    emp.call("events",{"events":events},token=token)
    assert payroll.call("payroll",scope)["changed"]
    payroll.call("close",scope,409)
    sheet=manager.call("sheet",scope)
    manager.call("approve",dict(scope,fingerprint=sheet["fingerprint"],note="Zkontrolovány plánované směny."))
    changes = payroll.call("export-changes",dict(scope,version=1))
    assert changes and all(c["employee"] == uid for c in changes)
    assert len(payroll.call("close",scope)["versions"])==2
    assert payroll.call("export-changes",dict(scope,version=1,to_version=2)) == changes
    assert payroll.call("export-changes",dict(scope,version=2)) == []
    assert payroll.call("download",dict(scope,version=version))["csv"]==csv
    assert len(payroll.call("close", scope)["versions"]) == 2

    # A manager's manually recorded time also needs a separate reviewer and an export.
    manager_id = clients["managerA"][1]
    own_scope = {"employee": manager_id, "month": month}
    manager.call("request", {"kind":"PRACE", "from":month+"-05T08:00", "to":month+"-05T12:00"}, 409)
    manager_profile = next(p for p in admin.call("people") if p["id"] == manager_id)
    admin.call("employee", dict(manager_profile, roles=["payroll", "manager"], attendance_enabled=True))
    manager.call("me", status=401)
    assert manager.call("login", {"login":"managerA","password":pw})["roles"] == ["manager", "payroll"]
    assert manager.call("payroll", scope)["people"]
    assert manager.call("request-proof", req)["jpeg"].startswith("/9j/")
    own_request = manager.call("request", {"kind":"PRACE", "from":month+"-05T08:00", "to":month+"-05T12:00"})
    manager.call("decide", dict(own_request, status="approved"), 403)
    clients["managerB"][0].call("decide", dict(own_request, status="approved"), 403)
    sheet = manager.call("sheet", own_scope)
    manager.call("approve", dict(own_scope, fingerprint=sheet["fingerprint"]), 403)
    assert not next(p for p in payroll.call("payroll", scope)["people"] if p["id"] == manager_id)["approved"]
    payroll.call("close", scope, 409)
    reviewer_id = admin.call("employee", {"name":"Reviewer","login":"review","number":"review","org_unit_id":units["A"],
        "roles":["manager"],"attendance_enabled":False,"starts":month+"-01"})["id"]
    invitation = admin.call("invite", {"id":reviewer_id})
    reviewer = Client(connect)
    reviewer.call("activate", dict(invitation, password=pw))
    reviewer.call("login", {"login":"review","password":pw})
    cover = {"starts":day_start.date().isoformat(), "ends":day_start.date().isoformat(), "substitute":reviewer_id}
    manager.call("manager-cover",dict(cover,substitute=clients["managerB"][1]),403)
    manager.call("manager-cover",cover)
    manager.call("manager-cover",cover,409)
    entries = reviewer.call("attendance-overview",dict(scope,team=True))["covers"]
    assert len(entries) == 1 and entries[0]["active"]
    reviewer.call("manager-cover",{"revoke":entries[0]["id"]},404)
    manager.call("manager-cover",{"revoke":entries[0]["id"]})
    assert not reviewer.call("attendance-overview",dict(scope,team=True))["covers"]
    reviewer.call("decide", dict(own_request, status="approved"))
    sheet = reviewer.call("sheet", own_scope)
    assert sheet["seconds"] == 4 * 3600
    reviewer.call("approve", dict(own_scope, fingerprint=sheet["fingerprint"]))
    versions = payroll.call("close", scope)["versions"]
    assert len(versions) == 3
    assert "managerA;" in payroll.call("download",dict(scope,version=versions[0]["version"]))["csv"]
    assert payroll.call("download",dict(scope,version=version))["csv"] == csv

    # Replay a night shift out of order at the start of the employment/month.
    midnight = datetime.fromisoformat(month+"-01").replace(tzinfo=zone)
    cross_month = [
        {"id":"overnight."+kind, "session":"overnight", "sid":sid, "kind":kind,
         "at":(midnight + timedelta(minutes=minute)).isoformat()}
        for kind, minute in [("logout",120), ("resume",90), ("sleep",30), ("login",-60)]
    ]
    receipt = anonymous.call("events", {"events":cross_month}, token=token, web=False)["results"]
    assert all(item["status"] == "accepted" for item in receipt)
    sheet = manager.call("sheet", scope)
    assert sheet["seconds"] == 16 * 3600 and not sheet["approved"] and not sheet["issues"]
    old_month = (midnight - timedelta(days=1)).strftime("%Y-%m")
    assert emp.call("sheet", {"month":old_month})["rows"] == []
    emp.call("request", {"kind":"DOVOLENA", "from":old_month+"-20", "to":month+"-02"}, 422)
    manager.call("approve", {"employee":uid, "month":old_month, "fingerprint":""}, 409)
    admin.call("employee", {"name":"Bad number","login":"bad-number","number":"+demo","org_unit_id":units["A"],
        "roles":["employee"],"attendance_enabled":True,"starts":month+"-01"}, 422)
    # If all employments were dated incorrectly the correction must be able to withdraw all rows.
    for profile in admin.call("people"):
        if profile["id"] in (uid, manager_id):
            admin.call("employee", dict(profile, starts=datetime.now(zone).replace(day=1).strftime("%Y-%m-%d")))
    assert payroll.call("payroll", scope)["changed"] and payroll.call("payroll", scope)["people"] == []
    correction = payroll.call("close", scope)["versions"]
    assert len(correction) == 4
    assert payroll.call("download",dict(scope,version=correction[0]["version"]))["csv"].count("\n") == 1
    assert payroll.call("download",dict(scope,version=version))["csv"] == csv
    payroll.call("payroll", {"month":"0000-01"}, 422)

    # Combining roles expands read access, while write and proof access stay team-scoped.
    manager_b, manager_b_id = clients["managerB"]
    manager_b_profile = next(p for p in admin.call("people") if p["id"] == manager_b_id)
    admin.call("employee", dict(manager_b_profile, roles=["manager", "payroll"]))
    manager_b.call("login", {"login":"managerB","password":pw})
    assert all("note" not in request and "reason" not in request for request in manager_b.call("sheet", scope)["requests"])
    manager_b.call("request-proof", req, 404)
    manager_b.call("decide", dict(req, status="rejected", reason="Test"), 403)
    manager_b.call("approve", dict(scope, fingerprint=""), 403)
    manager_b.call("request", dict(visit, employee=uid), 403)
    assert all(p["id"] != manager_b_id for p in manager_b.call("payroll", scope)["people"])

    employee_profile = next(p for p in admin.call("people") if p["id"] == uid)
    admin.call("employee", dict(employee_profile, attendance_enabled=False))
    skipped = dict(events[0], id="attendance-disabled")
    receipt = anonymous.call("events", {"events":[skipped]}, token=token, web=False)["results"]
    assert receipt == [{"id":"attendance-disabled", "status":"ignored"}]
    admin.call("employee", employee_profile)

    manager.call("login", {"login": "managerA", "password": pw})
    manager.call("attendance-merge-settings", status=403)
    assert admin.call("attendance-merge-settings") == {"gap_minutes": 0, "fund_tolerance": 15}
    admin.call("attendance-merge-limit", {"gap_minutes": -1}, 422)
    merge_day = day_start.date().isoformat()
    current_scope = {"employee": uid, "month": merge_day[:7]}
    extra_day = day_start.date().replace(day=1 if day_start.day != 1 else 2).isoformat()
    merge_events = [
        {"id":f"restart-{index}-{kind}", "session":f"restart.{index}", "kind":kind, "sid":sid,
         "at":datetime.fromisoformat(day+"T"+time).replace(tzinfo=zone).isoformat()}
        for index, (day, begin, end) in enumerate([(merge_day,"10:00","11:00"), (merge_day,"11:05","12:00"), (extra_day,"10:00","11:00")])
        for kind, time in [("login", begin), ("logout", end)]
    ]
    anonymous.call("events", {"events": merge_events}, token=token, web=False)
    open_sheet = manager.call("sheet", current_scope)
    selected = [index for index, row in enumerate(open_sheet["rows"]) if row["from"][11:16] in ["10:00", "11:05"]]
    same_day = [index for index in selected if open_sheet["rows"][index]["day"] == merge_day]
    assert len(same_day) == 2 and len(selected) == 3
    merge = dict(current_scope, fingerprint=open_sheet["fingerprint"], rows=same_day)
    manager.call("merge-work-rows", dict(merge, rows=selected), 422)
    manager_b.call("merge-work-rows", merge, 403)
    manager.call("merge-work-rows", merge)
    merged = manager.call("sheet", current_scope)
    correction = next(request for request in merged["requests"] if request.get("merged_work"))
    assert merged["seconds"] == open_sheet["seconds"] + 300
    assert len(merged["sessions"]) == len(open_sheet["sessions"])
    manager.call("merge-work-rows", merge, 409)
    manager.call("decide", {"id": correction["id"], "status": "rejected", "reason": "Vrácení spojení"})
    assert manager.call("sheet", current_scope)["seconds"] == open_sheet["seconds"]
    assert admin.call("attendance-merge-limit", {"gap_minutes": 5}) == {"gap_minutes": 5}
    assert manager.call("sheet", current_scope)["seconds"] == open_sheet["seconds"] + 300

    manual_day = month + "-12"
    manual_person = admin.call("employee", {
        "name": "Ruční evidence", "login": "manual-time", "number": "MANUAL", "roles": ["employee"],
        "org_unit_id": warehouse, "attendance_enabled": True, "starts": month + "-10", "ends": month + "-20",
    })["id"]
    manual_scope = {"employee": manual_person, "month": month}
    manual_sheet = manager.call("sheet", manual_scope)
    assert manual_sheet["attendance_start"] == month + "-10" and manual_sheet["attendance_end"] == month + "-20"
    manual = dict(manual_scope, fingerprint=manual_sheet["fingerprint"], day=manual_day,
                  **{"from": "08:00:01", "to": "16:00:01", "reason": "Práce bez počítače"})
    payroll.call("save-work-row", manual, 403)
    manager_b.call("save-work-row", manual, 403)
    manager.call("save-work-row", dict(manual, reason=" "), 422)
    manager.call("save-work-row", dict(manual, day=month + "-09"), 422)
    manager.call("save-work-row", manual)
    manager.call("save-work-row", manual, 409)
    manual_sheet = manager.call("sheet", manual_scope)
    assert manual_sheet["seconds"] == 28800 and manual_sheet["rows"][0]["from"].startswith(manual_day + "T08:00:01")
    manager.call("approve", dict(manual_scope, fingerprint=manual_sheet["fingerprint"]))
    update = dict(manual, fingerprint=manual_sheet["fingerprint"], row=0, to="15:00:01", reason="Upřesnění konce práce")
    manager.call("save-work-row", update)
    corrected = manager.call("sheet", manual_scope)
    assert corrected["seconds"] == 25200 and not corrected["approved"]
    change = next(r for r in corrected["requests"] if "replaces_from" in r)
    assert change["replaces_to"].startswith(manual_day + "T16:00:01") and change["note"] == update["reason"]
    assert len(corrected["rows"]) == 1 and not corrected["sessions"]

    sid_catalog = "S-1-5-21-1-2-3-45"
    subject = admin.call("employee", {
        "name": "Čtyřhodinový úvazek", "login": "catalog-worker", "number": "CATALOG", "roles": ["employee"],
        "org_unit_id": warehouse, "attendance_enabled": True, "starts": "2024-03-25", "sid": sid_catalog,
    })
    subject_login = Client(connect)
    subject_login.call("activate", dict(admin.call("invite", subject), password=pw))
    subject_login.call("login", {"login": "catalog-worker", "password": pw})
    employee = subject["id"]
    november_holiday = {"employee": employee, "from": "2025-11-17", "to": "2025-11-17"}
    no_model = manager.call("holiday-control", november_holiday)
    assert no_model["added"] == 0 and no_model["skipped"]["missing_model"] == 1
    holiday_visit = manager.call("request", {"employee": employee, "kind": "LEKAR",
        "from": "2025-11-17T08:00", "to": "2025-11-17T09:00"})
    occupied = manager.call("holiday-control", november_holiday)["skipped"]
    assert occupied["missing_model"] == 1 and occupied["occupied"] == 1
    manager.call("decide", dict(holiday_visit, status="rejected", reason="Návštěva se nekonala"))
    vacation = {"employee": employee, "kind": "DOVOLENA", "from": "2024-03-28", "to": "2024-04-03"}
    manager.call("request-preview", vacation, 409)
    model = {"name": "Dopolední", "effective_on": "2024-03-25",
             "week": [{"start": "08:00", "end": "12:00"}] * 5 + [None, None]}
    manager.call("work-model", model, 403)
    model_id = admin.call("work-model", model)["id"]
    admin.call("employee-work-model", {"model": model_id, "employee": employee, "effective_on": "2024-03-25"})
    admin.call("work-model", dict(model, id=model_id, effective_on="2024-04-02", week=[
        {"start": "09:00", "end": "14:00", "break_start": "11:30", "break_end": "12:00"}
    ] * 5 + [None, None]))
    november_preview = manager.call("holiday-control", november_holiday)
    assert november_preview["added"] == 1 and november_preview["skipped"]["missing_model"] == 0
    assert manager.call("holiday-control", dict(november_holiday, token=november_preview["token"]))["added"] == 1
    assert manager.call("holiday-control", november_holiday)["skipped"]["unchanged"] == 1
    november_sheet = manager.call("sheet", {"employee": employee, "month": "2025-11"})
    assert sum(r["seconds"] for r in november_sheet["rows"] if r.get("generated_holiday")) == 16200
    estimate = manager.call("request-preview", vacation)
    assert estimate["seconds"] == 46800 and estimate["days"] == ["2024-03-28", "2024-04-02", "2024-04-03"]
    assert estimate["balances"][0]["available"] is None
    opening = {"effective_on":"2024-01-01", "csv":"osobni_cislo;hodiny\nCATALOG;40"}
    subject_login.call("vacation-balance-import",opening,403)
    assert hr.call("vacation-balance-import",opening)["count"] == 1
    hr.call("vacation-balance-import",dict(opening,csv=opening["csv"].replace(";40",";4")+"\nUNKNOWN;5"),422)
    balance = manager.call("request-preview", vacation)["balances"][0]
    assert balance["available"] == 144000 and balance["after"] == 97200, balance
    request = manager.call("request", vacation)
    manager.call("decide", dict(request, status="approved"))
    booked = manager.call("request-preview",dict(vacation, **{"from":"2024-04-08","to":"2024-04-08"}))["balances"][0]
    assert booked["available"] == 97200, booked
    training = {"code": "SKOLENI", "name": "Školení", "description": "", "position": 50, "behavior": "work",
                "requires_proof": True, "active": True, "payroll_code": "SKOL", "effective_on": "2024-04-01"}
    admin.call("attendance-account", training)
    workshop = {"employee": employee, "kind": "SKOLENI", "from": "2024-04-04T10:00", "to": "2024-04-04T11:00"}
    claim = manager.call("request", workshop)
    overlap = manager.call("request", workshop)
    manager.call("decide", dict(claim, status="approved"))
    manager.call("decide", dict(overlap, status="approved"), 409)
    manager.call("decide", dict(overlap, status="rejected", reason="Duplicitní žádost"))
    admin.call("attendance-account", {**training, "behavior": "timed_absence"}, 409)
    admin.call("attendance-account", {k: v for k, v in dict(training, requires_proof=False, active=False).items() if k != "payroll_code"})
    manager.call("request", workshop, 422)
    events_catalog = [
        {"id": "catalog." + kind, "session": "catalog", "sid": sid_catalog, "kind": kind, "at": day}
        for kind, day in [("login", "2024-04-04T08:00:00+02:00"), ("logout", "2024-04-04T12:00:00+02:00")]
    ]
    anonymous.call("events", {"events": events_catalog}, token=token, web=False)
    catalog_scope = {"employee": employee, "month": "2024-04"}
    catalog_sheet = manager.call("sheet", catalog_scope)
    assert all(r["kind"] != "SVATEK" for r in catalog_sheet["rows"])
    fill("2024-04-01", "2024-04-01", "--dry-run")
    assert not any(r["kind"] == "SVATEK" for r in manager.call("sheet", catalog_scope)["rows"])
    # Two workers can inspect the same empty holiday. One inserts it, the other sees it already stored.
    from concurrent.futures import ThreadPoolExecutor
    with ThreadPoolExecutor(max_workers=2) as workers:
        results = list(workers.map(lambda _: fill("2024-04-01", "2024-04-01"), range(2)))
    assert any("Přidáno 1" in result for result in results) and any("Přidáno 0" in result for result in results)
    catalog_sheet = manager.call("sheet", catalog_scope)
    totals = {t["code"]: t["seconds"] for t in catalog_sheet["account_totals"]}
    assert totals["PRACE"] == 14400 and totals["SKOLENI"] == 3600 and totals["DOVOLENA"] == 32400
    assert catalog_sheet["missing_proofs"] == 1
    manager.call("approve", dict(catalog_scope, fingerprint=catalog_sheet["fingerprint"]), 409)
    subject_login.call("request-proof-upload", dict(claim, photo=photo))
    payroll.call("request-proof", claim, 404)
    catalog_sheet = manager.call("sheet", catalog_scope)
    manager.call("approve", dict(catalog_scope, fingerprint=catalog_sheet["fingerprint"], note="Prověřen fond."))
    version = payroll.call("close", catalog_scope)["versions"][0]["version"]
    export = payroll.call("download", dict(catalog_scope, version=version))["csv"]
    assert export.splitlines()[0].endswith(";sekundy")
    assert "CATALOG;2024-04-04;SKOL;2024-04-04T10:00:00+02:00;2024-04-04T11:00:00+02:00;3600" in export
    assert sum(int(row.split(";")[-1]) for row in export.splitlines() if ";DOVOLENA;" in row) == totals["DOVOLENA"]

    # Even though the vacation crosses Easter Monday, this non-chargeable holiday is an empty day.
    manager.call("calendar-settings", status=403)
    manager.call("calendar-day", {"day": "2024-04-04", "account": "SVATEK", "name": "Volno"}, 403)
    settings = admin.call("calendar-settings")
    assert settings["holiday_accounts"] == [{"effective_on": "0001-01-01", "account": "SVATEK"}]
    assert not settings["days"]
    assert catalog_sheet["calendar_days"]["2024-04-01"] == {"name": "Velikonoční pondělí", "account": "SVATEK"}
    assert totals["SVATEK"] == 14400
    assert "CATALOG;2024-04-01;SVATEK;;;14400" in export
    calendar = {"code": "FIRMA", "name": "Firemní volno", "position": 45, "behavior": "calendar",
                "requires_proof": False, "active": True, "description": "", "payroll_code": "VOL", "effective_on": "2024-03-01"}
    admin.call("attendance-account", calendar)
    admin.call("calendar-holiday-account", {"effective_on": "2024-04-01", "account": "SKOLENI"}, 422)
    admin.call("calendar-holiday-account", {"effective_on": "2024-04-01", "account": "FIRMA"})
    good_friday = next(r for r in settings["holidays"] if r["code"] == "VELKY_PATEK")
    assert good_friday["valid_from"] == "2015-12-21" and good_friday["easter_offset"] == -2
    sample = {"code": "TEST_SVATEK", "name": "Zkušební svátek", "valid_from": "2024-04-12", "valid_to": "2024-04-12", "month": 4, "day": 12}
    manager.call("calendar-holiday", sample, 403)
    admin.call("calendar-holiday", dict(sample, month=2, day=30), 422)
    admin.call("calendar-holiday", dict(sample, easter_offset=1), 422)
    rule_id = admin.call("calendar-holiday", sample)["id"]
    admin.call("calendar-holiday", dict(sample, valid_from="2024-04-11"), 409)
    admin.call("calendar-holiday", dict(sample, id=rule_id, code="JINY"), 422)
    assert manager.call("sheet", catalog_scope)["calendar_days"]["2024-04-12"]["name"] == sample["name"]
    admin.call("calendar-holiday", dict(sample, id=rule_id, day=13, valid_to="2024-04-13"))
    rule_sheet = manager.call("sheet", catalog_scope)
    assert "2024-04-12" not in rule_sheet["calendar_days"] and "2024-04-13" in rule_sheet["calendar_days"]
    assert not any(r["day"] == "2024-04-13" for r in rule_sheet["rows"])
    next_version = {"code": sample["code"], "name": "Po Velikonocích", "valid_from": "2025-01-01", "easter_offset": 2}
    active_rule_id = admin.call("calendar-holiday", next_version)["id"]
    assert manager.call("sheet", dict(catalog_scope, month="2025-04"))["calendar_days"]["2025-04-22"]["name"] == next_version["name"]
    day_off = {"day": "2024-04-09", "name": "Inventurní volno", "account": "FIRMA"}
    admin.call("calendar-day", dict(day_off, day="2024-04-01"), 409)
    admin.call("calendar-day", day_off)
    admin.call("calendar-day", dict(day_off, day="2024-04-04"))
    admin.call("calendar-day", dict(day_off, day="2024-04-06"))
    admin.call("calendar-day", dict(day_off, day="2024-04-10"))
    skip_example = manager.call("holiday-control", {"employee": employee, "from": "2024-04-04", "to": "2024-04-06"})
    assert skip_example["skipped"] == {"missing_model": 0, "without_shift": 1, "occupied": 1, "unchanged": 0}
    assert "Přidáno 2, změněno 1, odebráno 0" in fill("2024-04-01", "2024-04-10")
    admin.call("attendance-account", dict(calendar, behavior="work"), 409)
    manager.call("request-preview", dict(vacation, **{"from": day_off["day"], "to": day_off["day"]}), 422)
    manager.call("request", dict(workshop, kind="FIRMA"), 422)
    changed_sheet = manager.call("sheet", catalog_scope)
    assert not changed_sheet["approved"] and changed_sheet["seconds"] == 14400
    assert changed_sheet["calendar_days"]["2024-04-06"]["account"] == "FIRMA"
    assert not any(r["day"] == "2024-04-06" for r in changed_sheet["rows"])
    assert not any(r["day"] == "2024-04-04" and r["kind"] == "FIRMA" for r in changed_sheet["rows"])
    assert sum(r["seconds"] for r in changed_sheet["rows"] if r["kind"] == "FIRMA" and r["day"] == day_off["day"]) == 16200
    add = dict(catalog_scope, fingerprint=changed_sheet["fingerprint"], day=day_off["day"],
               **{"from": "17:00", "to": "18:00", "reason": "Práce při inventuře"})
    manager.call("save-work-row", dict(add, kind="FIRMA"), 422)
    manager.call("save-work-row", add)
    changed_sheet = manager.call("sheet", catalog_scope)
    credited = [r for r in changed_sheet["rows"] if r["kind"] == "FIRMA" and r["day"] == day_off["day"]]
    assert not credited
    assert "Přidáno 0, změněno 0, odebráno 1" in fill("2024-04-09", "2024-04-10")
    # A pending timed request outside the nominal shift suppresses the whole day.
    pending = manager.call("request", {"employee": employee, "kind": "LEKAR", "from": "2024-04-10T20:00", "to": "2024-04-10T20:30"})
    assert not any(r["day"] == "2024-04-10" for r in manager.call("sheet", catalog_scope)["rows"])
    assert "odebráno 1" in fill("2024-04-10", "2024-04-10")
    manager.call("decide", dict(pending, status="rejected", reason="Zrušená návštěva"))
    assert "Přidáno 1" in fill("2024-04-10", "2024-04-10")
    # Late logon removes credit from the sheet immediately, even without a logout.
    late_login = {"id": "holiday-open", "sid": sid_catalog, "session": "holiday-open", "at": "2024-04-10T20:01:00+02:00", "kind": "login"}
    anonymous.call("events", {"events": [late_login]}, token=token, web=False)
    after_open = manager.call("sheet", catalog_scope)
    assert not any(r["day"] == "2024-04-10" for r in after_open["rows"])
    manager.call("approve", dict(catalog_scope, fingerprint=after_open["fingerprint"], note="Ověřeny neúplné relace."))
    final_version = payroll.call("close", catalog_scope)["versions"][0]["version"]
    final_csv = payroll.call("download", dict(catalog_scope, version=final_version))["csv"]
    assert "2024-04-10;VOL;" not in final_csv and "2024-04-09;VOL;" not in final_csv and "2024-04-01;VOL;" in final_csv
    assert "odebráno 1" in fill("2024-04-10", "2024-04-10")
    assert "Přidáno 0, změněno 0, odebráno 0" in fill("2024-04-01", "2024-04-10")
    open_session = next(r for r in after_open["sessions"] if r["session"] == "holiday-open")
    reclassify = {"employee": employee, "device": open_session["device"], "session": "holiday-open", "service": True}
    admin.call("service-session-change", reclassify)
    assert "Přidáno 1" in fill("2024-04-10", "2024-04-10")
    service_sheet = manager.call("sheet", catalog_scope)
    assert sum(r["seconds"] for r in service_sheet["rows"] if r["day"] == "2024-04-10" and r["kind"] == "FIRMA") == 16200
    admin.call("service-session-change", dict(reclassify, service=False))
    assert not any(r["day"] == "2024-04-10" for r in manager.call("sheet", catalog_scope)["rows"])
    assert "odebráno 1" in fill("2024-04-10", "2024-04-10")
    assert changed_sheet["seconds"] == 18000
    admin.call("calendar-day", {"day": day_off["day"], "remove": True})
    restored_sheet = manager.call("sheet", catalog_scope)
    assert day_off["day"] not in restored_sheet["calendar_days"]
    assert payroll.call("download", dict(catalog_scope, version=version))["csv"] == export

    manual_vacation = dict(catalog_scope, fingerprint=restored_sheet["fingerprint"], day="2024-04-05",
                           kind="DOVOLENA", reason="Zápis dovolené ve výkazu")
    payroll.call("save-work-row", manual_vacation, 403)
    manager_b.call("save-work-row", manual_vacation, 403)
    manager.call("save-work-row", dict(manual_vacation, day="2024-04-01"), 422)
    manager.call("save-work-row", dict(manual_vacation, kind="SKOLENI"), 422)
    manager.call("save-work-row", manual_vacation)
    after_vacation = manager.call("sheet", catalog_scope)
    assert not after_vacation["approved"]
    assert sum(row["seconds"] for row in after_vacation["rows"] if row["day"] == "2024-04-05" and row["kind"] == "DOVOLENA") == 16200
    assert any(r["kind"] == "DOVOLENA" and r["status"] == "approved" and r["note"] == manual_vacation["reason"] for r in after_vacation["requests"])
    manager.call("save-work-row", manual_vacation, 409)
    manager.call("save-work-row", dict(manual_vacation, fingerprint=after_vacation["fingerprint"]), 409)
    manager.call("save-work-row", dict(manual_vacation, day="2024-04-08", kind="LEKAR", fingerprint=after_vacation["fingerprint"],
                                       **{"from":"09:45","to":"10:15"}))
    assert manager.call("sheet", catalog_scope)["missing_proofs"] == 1
    # March still has the four-hour model. An explicitly chosen account overrides the global account once per day.
    march = dict(catalog_scope, month="2024-03")
    proof_day = "2024-03-25"
    leave_day = "2024-03-26"
    work_day = "2024-03-27"
    admin.call("calendar-day", {"day": proof_day, "name": "Prohlídka", "account": "LEKAR"})
    admin.call("calendar-day", {"day": leave_day, "name": "Celozávodní dovolená", "account": "DOVOLENA"})
    rule = {"code": "ZAKLAD", "name": "Výročí", "month": 3, "day": 27, "valid_from": work_day, "valid_to": work_day}
    admin.call("calendar-holiday", rule)
    admin.call("calendar-holiday", dict(rule, code="ZVLASTNI", name="Den závodu", account="PRACE"))
    admin.call("calendar-holiday", dict(rule, code="KOLIZE", account="LEKAR"), 409)
    assert "Přidáno 3" in fill(proof_day, work_day)
    result = manager.call("sheet", march)
    assert result["calendar_days"][work_day] == {"name": "Výročí / Den závodu", "account": "PRACE"}
    rows = [r for r in result["rows"] if r.get("generated_holiday") and r["day"] <= work_day]
    assert [(r["day"], r["kind"], r["seconds"]) for r in rows] == [(proof_day, "LEKAR", 14400), (leave_day, "DOVOLENA", 14400), (work_day, "PRACE", 14400)]
    # An existing vacation already spans 28 March. With a company vacation on that date, only the generated entry is charged.
    within_vacation = "2024-03-28"
    admin.call("calendar-day", {"day": within_vacation, "name": "Celozávodní dovolená", "account": "DOVOLENA"})
    assert "Přidáno 1" in fill(within_vacation, within_vacation)
    result = manager.call("sheet", march)
    matching = [r for r in result["rows"] if r["day"] == within_vacation]
    assert len(matching) == 1 and matching[0]["kind"] == "DOVOLENA" and matching[0]["generated_holiday"] and matching[0]["seconds"] == 14400
    assert result["seconds"] == 14400 and result["missing_proofs"] == 1
    auto = next(r for r in result["requests"] if r.get("generated_holiday"))
    assert "Chybí povinný doklad." in result["day_issues"][proof_day]["reasons"]
    aid = {"id": auto["id"]}
    manager.call("approve", dict(march, fingerprint=result["fingerprint"]), 409)
    manager.call("decide", dict(aid, status="rejected", reason="Ruční pokus"), 409)
    manager.call("request-proof-upload", dict(aid, photo=photo), 404)
    subject_login.call("request-proof-upload", dict(aid, photo=photo))
    assert manager.call("request-proof", aid)["jpeg"]
    hr.call("request-proof", aid, 404)
    payroll.call("request-proof", aid, 404)
    result = manager.call("sheet", march)
    assert result["missing_proofs"] == 0 and proof_day not in result.get("day_issues", {})
    assert result["approval_blockers"] == [] and result["approval_note_required"] == bool(result["fund"]["review"])
    manager.call("approve", dict(march, fingerprint=result["fingerprint"], note="Prověřen fond."))
    v = payroll.call("close", march)["versions"][0]["version"]
    original = payroll.call("download", dict(march, version=v))["csv"]
    assert f"CATALOG;{leave_day};DOVOLENA;" in original and f"CATALOG;{proof_day};LEKAR;" in original
    base = dict(march, fingerprint=result["fingerprint"], day=work_day, kind="PRACE",
                **{"from":"08:15","to":"09:00","reason":"Skutečná přítomnost"})
    index = next(i for i, r in enumerate(result["rows"]) if r["day"] == work_day)
    manager.call("save-work-row", dict(base, row=index), 422)
    manager.call("save-work-row", base)
    result = manager.call("sheet", march)
    assert result["seconds"] == 2700 and not result["approved"]
    manager.call("save-work-row", dict(base, fingerprint=result["fingerprint"], day=proof_day, kind="LEKAR"))
    result = subject_login.call("sheet", march)
    assert result["missing_proofs"] == 1 and not any(r.get("generated_holiday") for r in result["requests"])
    assert not any(r["day"] == proof_day and r.get("generated_holiday") for r in result["rows"])
    subject_login.call("request-proof", aid, 404)
    manager.call("request-proof", aid, 404)
    subject_login.call("request-proof-upload", dict(aid, photo=photo), 404)
    assert "odebráno 2" in fill(proof_day, work_day)
    assert payroll.call("download", dict(march, version=v))["csv"] == original

    rounding = {"effective_on":"2024-04-01", "mode":"down", "minutes":30}
    break_rule = {"effective_on":"2024-04-01", "enabled":True, "minutes":30, "preferred":240, "deadline":360}
    account = next(a for a in admin.call("attendance-accounts") if a["code"] == "PRACE")
    admin.call("attendance-account", dict(account, rounding=rounding, break_rule=break_rule))
    admin.call("attendance-account", dict(account,
        rounding=dict(rounding, effective_on="2024-05-01", mode="off"),
        break_rule=dict(break_rule, effective_on="2024-05-01", enabled=False)))
    selected_rule = next(a for a in admin.call("attendance-accounts") if a["code"] == "PRACE")
    assert selected_rule["rounding_rules"][-1]["mode"] == "down" and selected_rule["break_rules"][-1]["enabled"]
    test_day = "2024-04-15"
    current = manager.call("sheet", catalog_scope)
    manager.call("save-work-row", dict(catalog_scope, day=test_day, kind="PRACE", fingerprint=current["fingerprint"],
        **{"from":"06:00","to":"14:30","reason":"Celá směna"}))
    current = manager.call("sheet", catalog_scope)
    assert any(b["day"] == test_day and b["source"] == "proposed" and "T10:00:" in b["from"] for b in current["breaks"])
    second = next(i for i, row in enumerate(current["rows"]) if row["day"] == test_day and row["from"].startswith(test_day+"T10:30"))
    correction = dict(catalog_scope, row=second, seconds=12420, reason="Doložená oprava", fingerprint=current["fingerprint"])
    other_manager.call("adjust-recognized-work", correction, 403)
    manager.call("adjust-recognized-work", correction)
    current = manager.call("sheet", catalog_scope)
    assert current["rows"][second]["seconds"] == 12420 and current["rows"][second]["calculated_seconds"] == 14400
    assert current["internal_by_day"][test_day]["PRACE"] == {"seconds":25200,"recognized_seconds":26820}
    correction["fingerprint"] = current["fingerprint"]
    manager.call("adjust-recognized-work", dict(correction, seconds=None))
    current = manager.call("sheet", catalog_scope)
    manager.call("attendance-break", dict(catalog_scope, day=test_day, mode="none", reason="Pauza neproběhla", fingerprint=current["fingerprint"]))
    current = manager.call("sheet", catalog_scope)
    assert any(r["day"] == test_day and r["seconds"] == 30600 for r in current["rows"])

    half_day = "2024-04-16"
    half = dict(catalog_scope, kind="DOVOLENA", vacation_side="end", **{"from":half_day,"to":half_day})
    estimate = manager.call("request-preview", half)
    assert estimate["seconds"] == 8100
    half_request = manager.call("request", half)
    colleague = hr.call("employee", {"name":"Test pokrytí", "org_unit_id":warehouse, "attendance_enabled":True,
        "number":"COVER-FIXTURE","starts":half_day, "ends":half_day})
    hr.call("employee-work-model", {"model":model_id, "employee":colleague["id"], "effective_on":half_day})
    manager.call("request", {"employee":colleague["id"],"kind":"LEKAR","from":half_day+"T13:00",
        "to":half_day+"T14:30", "note":"Soukromá poznámka"})
    covered = next(p for p in manager.call("team-coverage",half_request) if p["name"] == "Test pokrytí")
    assert covered == {"name":"Test pokrytí","planned":8100,"absences":[{
        "status":"pending","from":half_day+"T13:00:00+02:00","to":half_day+"T14:00:00+02:00","whole_day":False}]}, covered
    colleague_info = next(p for p in hr.call("people") if p["id"] == colleague["id"])
    hr.call("employee", dict(colleague_info,attendance_enabled=False))
    manager.call("decide", dict(half_request, status="approved"), 409)
    subject_login.call("request-vacation-consent", half_request)
    manager.call("decide", dict(half_request, status="approved"))
    current = manager.call("sheet", catalog_scope)
    assert any(r["partial_vacation"] and r["seconds"] == 8100 and r["from"] == "" for r in current["rows"] if r["day"] == half_day)

    check_range = {"employee":employee, "from":"2024-04-01", "to":"2024-04-30"}
    other_manager.call("holiday-control", check_range, 403)
    preview = manager.call("holiday-control", check_range)
    assert manager.call("holiday-control", dict(check_range, token=preview["token"])) == {k:v for k,v in preview.items() if k != "token"}
    assert manager.call("holiday-control", dict(check_range, to="2024-05-03"), 422)

    model_change = dict(model, id=model_id, original_effective_on="2024-04-02", effective_on="2024-04-02", reason="Oprava dubnové verze",
        week=[{"start":"09:00", "end":"14:30", "break_start":"11:30", "break_end":"12:00"}] * 5 + [None,None])
    preview = admin.call("work-model-change-preview", model_change)
    assert any(entry["employee"] == employee and entry["month"] == "2024-04" and entry["requests"] for entry in preview["impact"])
    admin.call("work-model", dict(model_change, preview_token="neplatný"), 409)
    admin.call("work-model", dict(model_change, preview_token=preview["token"]))
    amended = manager.call("sheet", catalog_scope)
    assert amended["fingerprint"] != current["fingerprint"]
    assert any(r.get("partial_vacation") and r["seconds"] == 8100 for r in amended["rows"])

    today = datetime.now(zone).date()
    future = today + timedelta(days=2)
    future_rule = {"code":"BUDOUCI_SVATEK", "name":"Budoucí svátek", "valid_from":future.isoformat(),
                   "month":future.month, "day":future.day}
    future_rule_id = admin.call("calendar-holiday", future_rule)["id"]
    manager.call("calendar-holiday-remove", {"id":active_rule_id}, 403)
    admin.call("calendar-holiday-remove", {"id":0}, 422)
    admin.call("calendar-holiday-remove", {"id":future_rule_id})
    admin.call("calendar-holiday-remove", {"id":future_rule_id}, 404)
    history = dict(catalog_scope, month="2025-04")
    fill("2025-04-22", "2025-04-22")
    before_removal = manager.call("sheet", history)
    assert any(r["day"] == "2025-04-22" and r.get("generated_holiday") for r in before_removal["rows"])
    admin.call("calendar-holiday-remove", {"id":active_rule_id})
    admin.call("calendar-holiday-remove", {"id":rule_id})
    rules = {rule["id"]:rule for rule in admin.call("calendar-settings")["holidays"]}
    assert future_rule_id not in rules
    assert rules[active_rule_id]["valid_to"] == today.isoformat()
    assert rules[rule_id]["valid_to"] == "2024-04-13"
    assert "odebráno 0" in fill("2025-04-22", "2025-04-22")
    after_removal = manager.call("sheet", history)
    assert after_removal["fingerprint"] == before_removal["fingerprint"]
    assert after_removal["calendar_days"] == before_removal["calendar_days"]

    november = dict(catalog_scope, month="2025-11")
    shift = {"start": "08:00", "end": "16:30", "break_mode": "account"}
    updated = dict(model, id=model_id, effective_on="2025-11-01", week=[shift] * 5 + [None, None], reason="Celá směna")
    preview = admin.call("work-model-change-preview", updated)
    admin.call("work-model", dict(updated, preview_token=preview["token"]))
    holiday = november_holiday["from"]
    def full_day_rows(sheet, day):
        return [(r["from"], r["to"], r["seconds"]) for r in sheet["rows"] if r["day"] == day]
    assert full_day_rows(manager.call("sheet", november), holiday) == [("", "", 30600)]
    admin.call("attendance-account", dict(account, break_rule=dict(break_rule, effective_on=holiday)))
    day = "2025-11-18"
    leave = {"employee": employee, "kind": "DOVOLENA", "from": day, "to": day}
    assert manager.call("request-preview", dict(leave, vacation_side="end"))["seconds"] == 14400
    leave_request = manager.call("request", leave)
    manager.call("decide", dict(leave_request, status="approved"))
    result = manager.call("sheet", november)
    assert full_day_rows(result, holiday) == full_day_rows(result, day) == [("", "", 28800)]
    assert [(p["from"][11:16], p["to"][11:16]) for p in result["planned_shifts"][day]] == [("08:00", "12:00"), ("12:30", "16:30")]
    assert len(result["planned_shifts"]["2025-11-14"]) == 1
    manager.call("save-work-row", dict(november, day=day, kind="PRACE", fingerprint=result["fingerprint"],
        **{"from": "14:00", "to": "15:00", "reason": "Kontrola překryvu s dovolenou"}), 422)

    # Keep this historical employee and break rule out of the Docker browser test period.
    admin.call("attendance-account", dict(account, break_rule=dict(break_rule, effective_on="2025-12-01", enabled=False)))
    historical_person = next(person for person in admin.call("people") if person["id"] == employee)
    admin.call("employee", dict(historical_person, ends="2025-12-31"))

    admin.call("revoke",{"id":admin.call("devices")[0]["id"]})
    emp.call("events",{"events":[]},401,token=token)

    archived = admin.call("employee", {"name":"Archivace dnes", "login":"archive-today", "number":"ARCHIVE",
        "org_unit_id":units["A"], "roles":["employee"], "attendance_enabled":True, "starts":day_start.date().isoformat()})
    admin.call("employee-archive", dict(archived, archived=True))
    assert archived["id"] in [p["id"] for p in manager.call("daily-attendance")["people"]]

    from identity_checks import check_accounts
    check_accounts(connect, Client, admin, pw)

def exercise_setup(connect, token):
    client = Client(connect)
    assert client.call("setup-status") == {"available": True}
    account = {"name": "Testovací správce", "login": "root", "password": pw}
    client.call("setup", dict(account, token="invalid"), 403)
    client.call("setup", dict(account, token=token), 403, web=False)
    client.call("setup", dict(account, token=token))
    assert client.call("setup-status") == {"available": False}
    client.call("setup", dict(account, token=token), 409)


def exercise_browser(connect, port):
    admin = Client(connect)
    admin.call("login", {"login": "backup-admin", "password": pw})
    zone = ZoneInfo("Europe/Prague")
    previous_month = datetime.now(zone).replace(day=1) - timedelta(days=1)
    month = (previous_month.replace(day=1) - timedelta(days=1)).strftime("%Y-%m")
    credentials = {}
    test_team = "Browser-" + secrets.token_hex(4)
    browser_unit = admin.call("org-unit", {"name": test_team, "parent_id": None})["id"]
    sid = "S-1-5-21-101-102-103-104"
    model = admin.call("work-model", {"name": test_team, "effective_on": month+"-01",
        "week": [{"start": "08:00", "end": "16:00"}] * 7})["id"]
    for role, name in (
        ("employee", "Jana z prohlížeče"),
        ("manager", "Martin z prohlížeče"),
        ("payroll", "Klára z prohlížeče"),
    ):
        login = role + "-" + secrets.token_hex(6)
        person = admin.call("employee", {
            "login": login, "name": name, "number": login, "roles": [role], "attendance_enabled": role == "employee",
            "org_unit_id": browser_unit, "starts": month + "-01", "sid": sid if role == "employee" else "",
            **({"work_model": {"model": model, "effective_on": month+"-01"}} if role == "employee" else {}),
        })
        invitation = admin.call("invite", person)
        password = secrets.token_hex(16)
        Client(connect).call("activate", dict(invitation, password=password))
        credentials[role] = {"login": login, "password": password, "name": name}

    device = Client(connect)
    enrollment = admin.call("register", {"name": test_team})
    device_token = device.call("enroll", enrollment, web=False)["token"]
    events = [
        {"id": kind, "session": test_team, "sid": sid, "kind": kind,
         "at": datetime.fromisoformat(month + "-03T" + hour + ":00").replace(tzinfo=zone).isoformat()}
        for kind, hour in (("login", "08"), ("logout", "16"))
    ]
    receipt = device.call("events", {"events": events}, token=device_token, web=False)
    assert all(item["status"] == "accepted" for item in receipt["results"])

    config = {"baseURL": f"http://localhost:{port}/", "month": month, "credentials": credentials}
    subprocess.run(
        ["node", "web/tests/deployment.mjs"], input=json.dumps(config),
        cwd=ROOT, text=True, check=True,
    )


def exercise_deployment(port):
    def connect():
        return http.client.HTTPConnection("localhost", port, timeout=10)

    def get(path):
        conn = connect()
        try:
            conn.request("GET", path)
            response = conn.getresponse()
            assert response.status == 200, (path, response.status)
            return response.read()
        finally:
            conn.close()

    for attempt in range(30):
        try:
            assert b"Doch" in get("/")
            break
        except OSError:
            if attempt == 29:
                raise
            time.sleep(1)
    assert b"elements-api" in get("/docs/")
    assert len(get("/docs/elements/web-components.min.js")) > 1000
    spec = json.loads(get("/openapi.json"))
    def verify_refs(node):
        if isinstance(node, dict):
            if "$ref" in node:
                pointer = node["$ref"]
                assert pointer.startswith("#/"), pointer
                target = spec
                for key in pointer[2:].split("/"):
                    target = target[key.replace("~1", "/").replace("~0", "~")]
            for value in node.values():
                verify_refs(value)
        elif isinstance(node, list):
            for value in node:
                verify_refs(value)

    verify_refs(spec)
    for endpoint in ("events", "setup", "setup-status", "request-proof-upload", "service-session-link", "service-session-verify",
                     "service-session-change", "daily-attendance", "merge-work-rows", "attendance-merge-settings", "attendance-merge-limit",
                     "calendar-settings", "calendar-holiday-account", "calendar-day", "calendar-holiday", "calendar-holiday-remove"):
        assert "post" in spec["paths"]["/api/v1/" + endpoint]

    exercise_setup(connect, os.environ["SETUP_TOKEN"])
    compose_php = ["docker", "compose", "--project-name", os.environ["DEPLOYMENT_COMPOSE_PROJECT"], "--env-file", "/dev/null",
                   "-f", "compose.yaml", "-f", "deploy/compose.test.yaml", "exec", "-T", "app", "php"]
    exercise(connect, compose_php)
    if os.getenv("DEPLOYMENT_UI") == "1":
        exercise_browser(connect, port)
    print("Docker deployment workflow OK")


def verify_unit_migration():
    # Rebuild the pre-migration schema in a transaction and roll back the fixture.
    migration = ROOT / "migrations" / "015-organizational-units.sql"
    previous = [path.read_text() for path in sorted(migration.parent.glob("*.sql")) if path.name < migration.name]
    sql("BEGIN;\n" + "\n".join(previous) + """
        INSERT INTO employee (name,login,number,password_hash,roles,attendance_enabled,team,archived_on)
        VALUES ('A','a','100','!', '["employee"]',true,'Sklad',NULL),
               ('B','b',NULL,'!', '["admin"]',false,'Sklad',NULL),
               ('C','c','101','!', '["manager"]',true,'sklad',CURRENT_DATE);
    """ + migration.read_text() + """
        DO $$ BEGIN
            IF (SELECT count(*) FROM org_unit) <> 2 OR EXISTS (
                SELECT 1 FROM employee e JOIN org_unit_tree u ON u.id = e.org_unit_id
                WHERE u.parent_id IS NOT NULL OR u.path <> CASE WHEN e.login='c' THEN 'sklad' ELSE 'Sklad' END
            ) THEN RAISE EXCEPTION 'Invalid team migration'; END IF;
        END $$;
        ROLLBACK;
    """)


def exercise_development():
    sql("CREATE SCHEMA "+schema)
    try:
        verify_unit_migration()
        verify_catalog_migration()
        php("bin/console", "db:migrate")
        php("bin/console", "db:migrate")
        token = secrets.token_hex(32)
        with socket.socket() as s:
            s.bind(("127.0.0.1",0))
            port=s.getsockname()[1]
        with tempfile.TemporaryDirectory() as tmp, tempfile.TemporaryFile() as log:
            proc=subprocess.Popen([PHP,"-d","session.save_path="+tmp,"-S","127.0.0.1:"+str(port),os.getenv("PHP_TEST_ROUTER","public/index.php")],cwd=ROOT,env=dict(env, SETUP_TOKEN=token, PASSKEY_ORIGIN="http://localhost:"+str(port)),stdout=log,stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(("127.0.0.1",port),.1): break
                    except OSError: time.sleep(.05)
                def connect():
                    return http.client.HTTPConnection("127.0.0.1", port, timeout=10)

                exercise_setup(connect, token)
                exercise(connect, [PHP], check_locks=True)
                # Compare the batched daily schedule with the historical monthly plan for the entire mixed roster.
                php("-r", r"""
                    $di = require 'config/bootstrap.php';
                    $db = $di->getByType(Doctrine\DBAL\Connection::class);
                    $models = $di->getByType(Dochazka\Attendance\WorkModels::class);
                    $rules = $di->getByType(Dochazka\Attendance\Catalog::class)->durationRules('break')['PRACE'] ?? [];
                    $ids = $db->fetchFirstColumn('SELECT id FROM employee');
                    $first = $models::day('2025-11-01');
                    foreach ($ids as $id) $monthly[$id] = $models->schedule($id, $first, $first->modify('+1 month'));
                    foreach (['14', '17', '18'] as $day) {
                        $date = $models::day('2025-11-'.$day);
                        $daily = $models->dailySchedules($ids, $date, $rules);
                        foreach ($ids as $id) {
                            if ($daily[$id] !== array_intersect_key($monthly[$id], [$date->format('Y-m-d') => true])) {
                                throw new RuntimeException("Odlišný rozvrh osoby $id pro den $day.");
                            }
                        }
                    }
                """)
                sql("""DO $$ BEGIN
                    IF NOT EXISTS (SELECT 1 FROM audit a JOIN employee e ON e.id = a.actor
                        WHERE a.action='attendance-correction' AND e.login='managerA'
                        AND a.details->>'reason'='Upřesnění konce práce'
                        AND (a.details->'before'->>'to') LIKE '%T16:00:01%'
                        AND (a.details->'after'->>'to') LIKE '%T15:00:01%')
                    THEN RAISE EXCEPTION 'Missing audit entry for work correction'; END IF;
                END $$;""")
                php("bin/console", "account:admin", "console-operator", data=pw + "\n")
                assert Client(connect).call("login", {"login":"console-operator", "password":pw})["roles"] == ["admin"]
            except Exception:
                log.seek(0)
                print(log.read().decode()[-4200:])
                raise
            finally:
                proc.terminate()
                proc.wait()
        print("HTTP workflow OK")
    finally:
        sql("DROP SCHEMA "+schema+" CASCADE")


def verify_catalog_migration():
    migration = ROOT / "migrations" / "017-attendance-catalog.sql"
    previous = [path.read_text() for path in sorted(migration.parent.glob("*.sql")) if path.name < migration.name]
    sql("BEGIN;\n" + "\n".join(previous) + """
        INSERT INTO org_unit(name) VALUES ('Migrace');
        INSERT INTO employee(name, login, number, password_hash, roles, attendance_enabled, org_unit_id)
        VALUES ('Dřívější zaměstnanec','legacy','L','!', '["employee"]', true, (SELECT id FROM org_unit));
        INSERT INTO request(employee, kind, begins, ends, note, status)
        SELECT id, 'LEKAR', '2024-04-02 10:00+02'::timestamptz, '2024-04-02 11:00+02'::timestamptz, '', 'approved' FROM employee
        UNION ALL
        SELECT id, 'DOVOLENA', '2024-04-03 00:00+02'::timestamptz, '2024-04-04 00:00+02'::timestamptz, '', 'approved' FROM employee;
        INSERT INTO export(month,version,content,fingerprint,creator)
        SELECT '2024-04', 1, 'osobni_cislo;datum;druh;od;do', 'old', id FROM employee;
    """ + migration.read_text() + """
        DO $$ BEGIN
            IF NOT (SELECT requires_proof FROM request WHERE kind='LEKAR')
                OR (SELECT requires_proof FROM request WHERE kind='DOVOLENA')
                OR (SELECT content FROM export) <> 'osobni_cislo;datum;druh;od;do'
                OR EXISTS (SELECT 1 FROM employee_work_model)
            THEN RAISE EXCEPTION 'Existing catalog records changed unexpectedly'; END IF;
        END $$;
        INSERT INTO attendance_account(code, name, position, behavior) VALUES ('SVATEK', 'Moje konto', 70, 'work');
    """ + (migration.parent / "018-attendance-calendar.sql").read_text() + """
        DO $$ BEGIN
            IF (SELECT behavior FROM attendance_account WHERE code = 'SVATEK') <> 'work'
                OR (SELECT account FROM attendance_holiday_account) <> 'SVATEK_1'
                OR (SELECT behavior FROM attendance_account WHERE code = 'SVATEK_1') <> 'calendar'
            THEN RAISE EXCEPTION 'Holiday migration overwrote existing custom account'; END IF;
        END $$;
        ROLLBACK;
    """)


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "--deployment":
        exercise_deployment(int(sys.argv[2]))
    else:
        exercise_development()
