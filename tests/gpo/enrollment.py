import json
import re
import subprocess
import sys
from pathlib import Path

def call(host, op, expected, *, negotiate=False, token=None, body="{}"):
    args = ["curl", "-sS", "--max-time", "10", "-w", "\\n%{response_code}",
            "-H", "Content-Type: application/json", "--data", body]
    if negotiate:
        args += ["--negotiate", "-u", ":"]
    if token:
        args += ["-H", "Authorization: Bearer " + token]
    reply = subprocess.check_output(args + [f"http://{host}/api/v1/{op}"], text=True)
    content, code = reply.rsplit("\n", 1)
    assert code == str(expected), (host, op, expected, code)
    return json.loads(content) if code == "200" else None

gateway = "enroll.attend.test"
if sys.argv[1:] == ["revoked"]:
    call(gateway, "domain-enroll", 403, negotiate=True)
    sys.exit(0)

call(gateway, "domain-enroll", 401)
call("web", "domain-enroll", 403)
subprocess.run(["kinit", "lookup"], input=Path("/run/enroll-test/gpo_bind_password").read_text(),
               text=True, check=True, stdout=subprocess.DEVNULL)
call(gateway, "domain-enroll", 403, negotiate=True)
subprocess.run(["kinit", "-k", "-t", "/tmp/UNLISTED.keytab", "UNLISTED$"], check=True)
call(gateway, "domain-enroll", 403, negotiate=True)
subprocess.run(["kinit", "-k", "-t", "/tmp/PILOT.keytab", "PILOT$"], check=True)
first = call(gateway, "domain-enroll", 200, negotiate=True)["token"]
second = call(gateway, "domain-enroll", 200, negotiate=True)["token"]
assert re.fullmatch("[a-f0-9]{64}", second) and second != first
call("web", "events", 401, token=first, body='{"events":[]}')
assert call("web", "events", 200, token=second, body='{"events":[]}') == {"results": []}
events = [{"id": kind, "sid": "S-1-5-21-1-2-3-44", "session": "pilot.44", "kind": kind,
           "at": f"2026-11-10T{hour}:00:00+01:00"} for kind, hour in [("login", "08"), ("logout", "16")]]
for expected in ["accepted", "duplicate"]:
    result = call("web", "events", 200, token=second, body=json.dumps({"events": events}))
    assert result["results"] == [{"id": e["id"], "status": expected} for e in events]
