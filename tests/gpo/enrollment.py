import json
import hmac
import re
import secrets
import subprocess
import sys
import time
from pathlib import Path


def call(origin, op, expected, *, negotiate=False, authorization=None, token=None, body="{}", curl_options=()):
    args = ["curl", "-sS", "--max-time", "10", "-w", "\\n%{response_code}",
            "-H", "Content-Type: application/json", "--data", body]
    if negotiate:
        args += ["--negotiate", "-u", ":"]
    if token:
        args += ["-H", "Authorization: Bearer " + token]
    if authorization:
        args += ["-H", "Authorization: " + authorization]
    if "://" not in origin:
        origin = "http://" + origin
    reply = subprocess.check_output(args + list(curl_options) + [f"{origin}/api/v1/{op}"], text=True)
    content, code = reply.rsplit("\n", 1)
    assert code == str(expected), (origin, op, expected, code)
    return json.loads(content) if code == "200" else None


def native_checks(revoked):
    ip = subprocess.check_output(['hostname', '-i'], text=True).strip()
    gateway = 'https://gateway.attend.test:8443'
    options = ('--resolve', f'gateway.attend.test:8443:{ip}', '--cacert', '/tmp/gateway.pem')

    def enroll(status, negotiate=False):
        return call(gateway, 'domain-enroll', status, negotiate=negotiate, curl_options=options)

    if revoked:
        subprocess.run(['kinit', '-k', '-t', '/tmp/PILOT.keytab', 'PILOT$'], check=True)
        enroll(403, negotiate=True)
        return

    # A first request without credentials confirms the listener and the TLS trust chain.
    call(gateway, 'domain-enroll', 401, curl_options=options + ('--retry', '5', '--retry-connrefused', '--retry-delay', '1'))
    subprocess.run(['kinit', '-k', '-t', '/tmp/UNLISTED.keytab', 'UNLISTED$'], check=True)
    enroll(403, negotiate=True)
    subprocess.run(['kinit', '-k', '-t', '/tmp/PILOT.keytab', 'PILOT$'], check=True)
    issued = enroll(200, negotiate=True)['token']
    assert call('web', 'events', 200, token=issued, body='{"events":[]}') == {'results': []}

    key = bytes.fromhex(Path('/run/enroll-test/central-key').read_text().strip())

    def signed(principal, timestamp):
        nonce = secrets.token_hex(16)
        mac = hmac.digest(key, f'dochazka-gateway-v1\n{timestamp}\n{nonce}\n{principal}'.encode(), 'sha256').hex()
        return f'Dochazka-Gateway {principal}:{timestamp}:{nonce}:{mac}'

    def central(status, authorization=None):
        return call('https://central.test', 'gateway-enroll', status, authorization=authorization,
                    curl_options=('--cacert', '/run/enroll-test/central.pem'))

    central(403)
    central(403, signed('PILOT$@OTHER.TEST', int(time.time())))
    central(403, signed('PILOT$@ATTEND.TEST', int(time.time()) - 150))
    authorization = signed('PILOT$@ATTEND.TEST', int(time.time()))
    central(403, authorization.replace('PILOT$', 'UNLISTED$'))
    rotated = central(200, authorization)['token']
    central(403, authorization)
    assert rotated != issued
    call('web', 'events', 401, token=issued, body='{"events":[]}')
    call('web', 'events', 200, token=rotated, body='{"events":[]}')


if sys.argv[1:] in (['native'], ['native-revoked']):
    native_checks(sys.argv[1] == 'native-revoked')
    sys.exit(0)

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
