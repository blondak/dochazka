"""Exercise the actual HTTP endpoints against an isolated schema in a test PostgreSQL database."""
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

def exercise(connect):
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
    for role,team in [("employee","A"),("manager","A"),("payroll","B"),("manager","B")]:
        login = role + team
        uid = admin.call("employee", {"name":login,"login":login,"number":login,"team":team,"roles":[role],"attendance_enabled":role=="employee",
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
    events = [{"id":str(i),"session":"boot.1","sid":sid,"kind":kind,
               "at":datetime.fromisoformat(month+"-03T"+hour+":00").replace(tzinfo=zone).isoformat()}
              for i,(kind,hour) in enumerate([("logout","16"),("login","08")])]
    for expected in ["accepted","duplicate"]:
        results = anonymous.call("events", {"events": events}, token=token, web=False)["results"]
        assert [r["status"] for r in results] == [expected,expected]
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
    today_seconds = int((logout - day_start).total_seconds())
    anonymous.call("daily-attendance", status=401)
    emp.call("daily-attendance", status=403)
    admin.call("daily-attendance", status=403)
    assert clients["managerB"][0].call("daily-attendance")["people"] == []

    def employee_today(status, seconds=None):
        daily = manager.call("daily-attendance")
        assert daily["day"] == day_start.date().isoformat()
        assert daily["people"] == [{"id": uid, "name": "employeeA", "status": status, "worked_seconds": seconds}]

    employee_today("absent")
    current_events = [
        {"id":"day-login", "kind":"login", "session":"day.1", "sid":sid, "at":max(day_start-timedelta(hours=1), day_start.replace(day=1)).isoformat()},
        {"id":"day-logout", "kind":"logout", "session":"day.1", "sid":sid, "at":logout.isoformat()},
        {"id":"day-return", "kind":"login", "session":"day.2", "sid":sid, "at":now.isoformat()},
    ]
    anonymous.call("events", {"events": current_events[:2]}, token=token, web=False)
    employee_today("finished", today_seconds)
    anonymous.call("events", {"events": current_events[2:]}, token=token, web=False)
    employee_today("present", today_seconds)
    admin.call("service-session-change", dict(change, session="day.2", service=True))
    employee_today("finished", today_seconds)
    anonymous.call("events", {"events": [dict(current_events[2], id="day-return-logout", kind="logout")]}, token=token, web=False)
    visit = {"kind": "LEKAR", "from": month+"-03T10:00", "to": month+"-03T11:00", "note":"Poznámka pro vedoucího"}
    photo = "iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgGB4AAADIAAH4XVuUAAAAAElFTkSuQmCC"
    emp.call("request", dict(visit, photo=photo), 422)
    req = emp.call("request", visit)
    upload = dict(req, photo=photo)
    emp.call("request-proof-upload", upload, 409)
    manager.call("request-proof", req, 404)
    payroll.call("close", scope, 409)
    manager.call("decide", dict(req, status="approved"))

    sheet = manager.call("sheet", scope)
    assert sheet["seconds"] == 25200 and len(sheet["rows"]) == 3
    assert sheet["pending"] == 0 and sheet["missing_proofs"] == 1
    assert "T" in sheet["requests"][0]["begins"] and "T" in sheet["requests"][0]["ends"]
    manager.call("approve", dict(scope, fingerprint=sheet["fingerprint"]), 409)
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

    emp.call("request-proof-upload", upload)
    emp.call("request-proof-upload", upload, 409)
    assert manager.call("request-proof",req)["jpeg"].startswith("/9j/")
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
    manager.call("approve",dict(scope,fingerprint=sheet["fingerprint"]))
    version=payroll.call("close",scope)["versions"][0]["version"]
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
    manager.call("approve",dict(scope,fingerprint=sheet["fingerprint"]))
    assert len(payroll.call("close",scope)["versions"])==2
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
    reviewer_id = admin.call("employee", {"name":"Reviewer","login":"review","number":"review","team":"A",
        "roles":["manager"],"attendance_enabled":False,"starts":month+"-01"})["id"]
    invitation = admin.call("invite", {"id":reviewer_id})
    reviewer = Client(connect)
    reviewer.call("activate", dict(invitation, password=pw))
    reviewer.call("login", {"login":"review","password":pw})
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
    admin.call("employee", {"name":"Bad number","login":"bad-number","number":"+demo","team":"A",
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
    assert admin.call("attendance-merge-settings") == {"gap_minutes": 0}
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

    admin.call("revoke",{"id":admin.call("devices")[0]["id"]})
    emp.call("events",{"events":[]},401,token=token)

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
    sid = "S-1-5-21-101-102-103-104"
    for role, name in (
        ("employee", "Jana z prohlížeče"),
        ("manager", "Martin z prohlížeče"),
        ("payroll", "Klára z prohlížeče"),
    ):
        login = role + "-" + secrets.token_hex(6)
        person = admin.call("employee", {
            "login": login, "name": name, "number": login, "roles": [role], "attendance_enabled": role == "employee",
            "team": test_team, "starts": month + "-01", "sid": sid if role == "employee" else "",
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
                     "service-session-change", "daily-attendance", "merge-work-rows", "attendance-merge-settings", "attendance-merge-limit"):
        assert "post" in spec["paths"]["/api/v1/" + endpoint]

    exercise_setup(connect, os.environ["SETUP_TOKEN"])
    exercise(connect)
    if os.getenv("DEPLOYMENT_UI") == "1":
        exercise_browser(connect, port)
    print("Docker deployment workflow OK")


def exercise_development():
    sql("CREATE SCHEMA "+schema)
    try:
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
                exercise(connect)
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


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "--deployment":
        exercise_deployment(int(sys.argv[2]))
    else:
        exercise_development()
