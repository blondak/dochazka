#!/usr/bin/python3
import hashlib
import hmac
import json
import os
import re
import secrets
import ssl
import sys
import time
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import HTTPSHandler, HTTPRedirectHandler, ProxyHandler, Request, build_opener

CONFIG = Path('/etc/dochazka-gateway')


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def reply(status, payload):
    print(f'Status: {status}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\n\r\n'
          f'{json.dumps(payload)}', end='')


def register(principal):
    timestamp = int(time.time())
    nonce = secrets.token_hex(16)
    key = bytes.fromhex((CONFIG / 'central-key').read_text().strip())
    if len(key) != 32:
        raise ValueError('Invalid central key')
    message = f'dochazka-gateway-v1\n{timestamp}\n{nonce}\n{principal}'.encode('ascii')
    signature = hmac.new(key, message, hashlib.sha256).hexdigest()
    request = Request(os.environ['GATEWAY_CENTRAL'] + '/api/v1/gateway-enroll', data=b'{}', headers={
        'Authorization': f'Dochazka-Gateway {principal}:{timestamp}:{nonce}:{signature}',
        'Content-Type': 'application/json',
    })
    ca = CONFIG / 'central-ca.pem'
    tls = ssl.create_default_context(cafile=str(ca) if ca.is_file() else None)
    upstream = build_opener(HTTPSHandler(context=tls), NoRedirect(), ProxyHandler({}))
    with upstream.open(request, timeout=8) as response:
        data = json.loads(response.read(1025))
        token = data.get('token', '') if isinstance(data, dict) else ''
        if response.status != 200 or not isinstance(token, str) or not re.fullmatch(r'[a-f0-9]{64}', token):
            raise ValueError('Invalid central response')
        return token


def main():
    principal = os.environ.get('GSS_NAME', '')
    if len(principal) > 254 or not re.fullmatch(r'[A-Z0-9][A-Z0-9-]{0,62}\$@[A-Z0-9][A-Z0-9.-]*',
                                                principal, re.IGNORECASE | re.ASCII):
        return reply('403 Forbidden', {'error': 'Je vyžadováno doménové ověření stanice.'})
    try:
        body = sys.stdin.buffer.read(min(1025, int(os.environ.get('CONTENT_LENGTH') or '0')))
        if json.loads(body) != {}:
            raise ValueError('Unexpected enrollment payload')
    except ValueError:
        return reply('422 Unprocessable Content', {'error': 'Registrace očekává prázdný objekt.'})

    try:
        return reply('200 OK', {'token': register(principal.upper())})
    except HTTPError as error:
        print(f'Enrollment rejected by central with HTTP {error.code}', file=sys.stderr)
        if error.code == 403:
            return reply('403 Forbidden', {'error': 'Centrála registraci nepovolila.'})
    except (OSError, ValueError, KeyError) as error:
        print(f'Enrollment failed: {type(error).__name__}', file=sys.stderr)
    return reply('502 Bad Gateway', {'error': 'Registrace v centrále není dostupná.'})


if __name__ == '__main__':
    main()
