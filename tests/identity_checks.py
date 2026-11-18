"""Account lifecycle assertions shared by the HTTP and Docker workflows."""
from datetime import datetime, timedelta
import json
import os
from pathlib import Path
import secrets
import subprocess
from zoneinfo import ZoneInfo


def check_accounts(connect, client_class, admin, password):
    today = datetime.now(ZoneInfo("Europe/Prague")).date()
    login = "session-test"
    profile = {
        "name": "Test relací", "login": login, "org_unit_id": admin.call("org-units")[0]["id"],
        "roles": ["employee"], "attendance_enabled": False, "starts": today.isoformat(),
    }
    admin.call("employee", dict(profile, starts="2024-02-30"), 422)
    admin.call("employee", dict(profile, ends=(today - timedelta(days=1)).isoformat()), 422)
    admin.call("employee", dict(profile, roles=[]), 422)
    admin.call("employee", dict(profile, roles=["manager", "invalid"]), 422)
    admin.call("employee", dict(profile, roles=["manager", "manager"]), 422)
    account = admin.call("employee", profile)
    profile.update(account)
    assert next(p for p in admin.call("people") if p["id"] == account["id"])["number"] is None
    admin.call("employee", dict(profile, attendance_enabled=True), 422)
    admin.call("employee", dict(profile, number="  ", attendance_enabled=True), 422)
    admin.call("employee", dict(profile, number="SESSIONS", attendance_enabled=True))
    admin.call("employee", profile)
    assert next(p for p in admin.call("people") if p["id"] == account["id"])["number"] == "SESSIONS"
    admin.call("employee", dict(profile, number=None))
    assert next(p for p in admin.call("people") if p["id"] == account["id"])["number"] is None
    last_admin = next(p for p in admin.call("people") if p["login"] == "root")
    admin.call("employee", dict(last_admin, roles=["employee"]), 409)
    backup = dict(profile, login="backup-admin", number="BACKUP", roles=["admin"])
    backup.pop("id")
    backup_id = admin.call("employee", backup)
    backup_invitation = admin.call("invite", backup_id)
    admin.call("employee", dict(last_admin, roles=["employee"]), 409)
    code = admin.call("invite", account)["code"]
    owner = client_class(connect)
    owner.call("activate", {"code": code, "password": password})
    owner.call("activate", {"code": code, "password": password}, 422)
    other = client_class(connect)
    credentials = {"login": login, "password": password}
    owner.call("login", credentials)
    other.call("login", credentials)
    owner.call("sheet", status=403)
    if os.getenv("WEBAUTHN_BROWSER") == "1":
        # Use this workflow's disposable employee and the real API on its random port.
        port = connect().port
        subprocess.run(
            ["node", "web/tests/authenticator.mjs"], cwd=Path(__file__).resolve().parents[1],
            input=json.dumps(dict(credentials, origin=f"http://localhost:{port}")),
            text=True, check=True,
        )
    new_password = secrets.token_hex(16)
    owner.call("change-password", {"current_password": password, "new_password": new_password})
    other.call("me", status=401)
    assert owner.call("me")["id"] == account["id"]
    directory = owner.call("people")
    assert len(directory) == 1 and "sid" not in directory[0] and "login" not in directory[0]
    code = admin.call("invite", account)["code"]
    other.call("activate", {"code": code, "password": password})
    owner.call("me", status=401)
    other.call("activate", {"code": code, "password": new_password}, 422)
    other.call("login", {"login": login, "password": new_password}, 401)
    other.call("login", credentials)
    assert other.call("passkeys")["keys"] == []
    admin.call("employee", dict(profile, starts=(today + timedelta(days=2)).isoformat()))
    other.call("me", status=401)
    other.call("login", credentials, 401)
    admin.call("employee", profile)
    other.call("me", status=401)
    other.call("login", credentials)
    admin.call("employee", dict(profile, roles=["manager", "payroll"]))
    other.call("me", status=401)
    assert other.call("login", credentials)["roles"] == ["manager", "payroll"]
    other.call("employee-archive", dict(account, archived=True), 403)
    admin.call("employee-archive", dict(last_admin, archived=True), 409)
    expired_invitation = admin.call("invite", account)
    admin.call("employee-archive", dict(account, archived=True))
    other.call("me", status=401)
    other.call("login", credentials, 401)
    admin.call("invite", account, 409)
    assert next(p for p in admin.call("people") if p["id"] == account["id"])["archived_on"] == today.isoformat()
    admin.call("employee-archive", dict(account, archived=False))
    other.call("me", status=401)
    other.call("activate", dict(expired_invitation, password=password), 422)
    other.call("login", credentials)
    other.call("logout")
    other.call("me", status=401)
    other.call("activate", dict(backup_invitation, password=password))
    admin.call("employee", dict(last_admin, roles=["employee"]))
    admin.call("me", status=401)
    other.call("login", {"login":"backup-admin", "password":password})
    assert any(p.get("login") == "root" for p in other.call("people"))
