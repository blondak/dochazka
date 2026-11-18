#!/usr/bin/python3
import argparse
import fcntl
import grp
import ipaddress
import os
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from string import Template
from urllib.parse import urlsplit


NAME = 'dochazka-gateway'
GROUP = 'Dochazka-PC'
CONFIG = Path('/etc') / NAME
SCRIPTS = Path('/usr/local/lib') / NAME
SOURCE = Path(__file__).resolve().parent
MODULES = ['apache2-bin', 'libapache2-mod-auth-gssapi', 'ca-certificates', 'ldap-utils', 'openssl']


def run(*args, capture=False, **kwargs):
    return subprocess.run(args, check=True, text=True, capture_output=capture, **kwargs).stdout


def dns_name(value):
    labels = value.split('.')
    if len(value) > 253 or len(labels) < 2 or not all(
        re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label) for label in labels
    ):
        raise argparse.ArgumentTypeError('Zadejte celé DNS jméno bez koncové tečky.')
    return value.lower()


def origin(value):
    url = urlsplit(value)
    if url.scheme != 'https' or not url.hostname or url.username is not None or url.password is not None or \
            url.path not in ('', '/') or url.query or url.fragment or not 0 < (url.port or 443) <= 65535:
        raise argparse.ArgumentTypeError('Zadejte HTTPS adresu centrály bez cesty a přihlašovacích údajů.')
    return f'https://{dns_name(url.hostname)}' + (f':{url.port}' if url.port else '')


def args_from_cli():
    parser = argparse.ArgumentParser(description='Nativní registrační brána Docházky na řadiči Debian / Samba AD.')
    parser.add_argument('--host', required=True, type=dns_name, help='interní DNS jméno brány')
    parser.add_argument('--listen', required=True, type=ipaddress.IPv4Address, help='IPv4 adresa řadiče v interní síti')
    parser.add_argument('--port', type=int, default=443, metavar='PORT')
    parser.add_argument('--allow', required=True, action='append', type=ipaddress.IPv4Network, metavar='CIDR')
    parser.add_argument('--central', required=True, type=origin)
    parser.add_argument('--key-file', required=True, type=Path, help='soubor se sdíleným klíčem centrály')
    parser.add_argument('--tls-cert', required=True, type=Path, help='certifikát brány a případné mezilehlé certifikáty')
    parser.add_argument('--tls-key', required=True, type=Path, help='nešifrovaný privátní klíč brány')
    parser.add_argument('--ldap-host', type=dns_name, help='výchozí je DNS jméno místního řadiče AD')
    parser.add_argument('--ldap-ca', type=Path, help='výchozí je tls cafile z konfigurace Samba')
    parser.add_argument('--central-ca', type=Path, help='vlastní CA pro HTTPS centrály')
    parser.add_argument('--pilot', action='append', default=[], metavar='PC', help='existující počítač pro pilot, volbu lze opakovat')
    parser.add_argument('--manage-dns', action='store_true', help='založit chybějící A záznam ve vnitřní AD zóně')
    parser.add_argument('--configure-only', action='store_true', help='připravit službu bez spuštění systemd')
    args = parser.parse_args()
    if not 1 <= args.port <= 65535:
        parser.error('--port musí být v rozsahu 1 až 65535')
    if args.listen.is_unspecified or args.listen.is_loopback or args.listen.is_multicast:
        parser.error('--listen musí být konkrétní adresa řadiče v interní síti')
    if any(network.prefixlen == 0 for network in args.allow):
        parser.error('--allow musí omezit přístup na firemní síť')
    return args


def write(path, data, mode=0o640, gid=0):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as temporary:
        target = Path(temporary.name)
        try:
            temporary.write(data.encode() if isinstance(data, str) else data)
            temporary.flush()
            os.fchown(temporary.fileno(), 0, gid)
            os.fchmod(temporary.fileno(), mode)
            target.replace(path)
        finally:
            target.unlink(missing_ok=True)


def samba_setting(name):
    return run('testparm', '-s', '--parameter-name', name, capture=True).strip()


def configured_account(db, username, password, marker):
    import ldb
    from samba import dsdb

    expression = f'(sAMAccountName={username})'
    attrs = ['description', 'sAMAccountType', 'userAccountControl', 'servicePrincipalName']
    entries = db.search(expression=expression, attrs=attrs)
    if not entries:
        db.newuser(username, password, useusernameascn=True, description=marker)
        entries = db.search(expression=expression, attrs=attrs)
    entry = entries[0]
    if entry.get('description', [b''])[0].decode() != marker or \
            int(entry['sAMAccountType'][0]) != dsdb.ATYPE_NORMAL_ACCOUNT:
        raise ValueError(f'Účet {username} už existuje a není spravovaný touto instalací.')
    flags = int(entry['userAccountControl'][0])
    if not flags & dsdb.UF_DONT_EXPIRE_PASSWD:
        update = ldb.Message(entry.dn)
        update['userAccountControl'] = ldb.MessageElement(
            str(flags | dsdb.UF_DONT_EXPIRE_PASSWD), ldb.FLAG_MOD_REPLACE, 'userAccountControl',
        )
        db.modify(update)
    return entry


def configure_ad(db, args, gid, ldap_host):
    import ldb

    password_file = CONFIG / 'ldap-password'
    if not password_file.exists():
        if db.search(expression='(sAMAccountName=dochazka-ldap)', attrs=['sAMAccountName']):
            raise ValueError(f'Účet dochazka-ldap už existuje. Prověřte jeho účel a obnovte {password_file}.')
        write(password_file, 'Az9!' + secrets.token_hex(32), gid=gid)
    bind = configured_account(db, 'dochazka-ldap', password_file.read_text().strip(), f'{NAME} LDAP {args.host}')
    ldap_url = f'ldaps://{ldap_host}'
    run('ldapsearch', '-LLL', '-x', '-H', ldap_url, '-D', str(bind.dn), '-y', str(password_file),
        '-b', db.domain_dn(), '-s', 'base', '-o', 'nettimeout=8', 'distinguishedName',
        env={**os.environ, 'LDAPTLS_CACERT': str(CONFIG / 'ldap-ca.pem'), 'LDAPTLS_REQCERT': 'demand'},
        stdout=subprocess.DEVNULL)

    http = configured_account(db, 'dochazka-http', 'Az9!' + secrets.token_hex(32), f'{NAME} HTTP {args.host}')
    spn = 'HTTP/' + args.host
    if not any(bytes(value).lower() == spn.lower().encode() for value in http.get('servicePrincipalName', [])):
        run('samba-tool', 'spn', 'add', spn, 'dochazka-http')
    with tempfile.TemporaryDirectory(dir=CONFIG) as staging:
        keytab = Path(staging) / 'http.keytab'
        run('samba-tool', 'domain', 'exportkeytab', str(keytab), '--principal=' + spn)
        write(CONFIG / 'http.keytab', keytab.read_bytes(), gid=gid)

    groups = db.search(expression=f'(sAMAccountName={GROUP})', attrs=['objectClass', 'groupType'])
    if not groups:
        db.newgroup(GROUP, description='Pocitace pro automatickou registraci Dochazky')
        groups = db.search(expression=f'(sAMAccountName={GROUP})', attrs=['objectClass', 'groupType'])
    group = groups[0]
    if b'group' not in group['objectClass'] or not int(group['groupType'][0]) & 0x80000000:
        raise ValueError(f'{GROUP} musí být bezpečnostní skupina AD.')
    for name in args.pilot:
        account = name.upper().rstrip('$') + '$'
        machines = db.search(expression=f'(&(objectClass=computer)(sAMAccountName={ldb.binary_encode(account)}))',
                             attrs=['sAMAccountName'])
        if not machines:
            raise ValueError(f'Počítač {account} nebyl v AD nalezen.')
        member = ldb.binary_encode(str(machines[0].dn))
        if not db.search(base=group.dn, scope=ldb.SCOPE_BASE, expression=f'(member={member})', attrs=['sAMAccountName']):
            db.add_remove_group_members(GROUP, [account])
    return str(bind.dn), str(group.dn), ldap_url


def configure_dns(args, ldap_host, zone):
    name = args.host.removesuffix('.' + zone)
    env = {**os.environ, 'KRB5_CONFIG': str(CONFIG / 'krb5.conf'), 'KRB5CCNAME': 'MEMORY:dochazka-gateway'}
    query = subprocess.run(['samba-tool', 'dns', 'query', ldap_host, zone, name, 'ALL', '-P'],
                           capture_output=True, text=True, env=env)
    if query.returncode and any(message in query.stderr for message in (
        'WERR_DNS_ERROR_NAME_DOES_NOT_EXIST', 'Record or zone does not exist.',
    )):
        run('samba-tool', 'dns', 'add', ldap_host, zone, name, 'A', str(args.listen), '-P', env=env)
    elif query.returncode:
        raise ValueError(f'DNS dotaz na {args.host} selhal. {query.stderr.strip()}')
    elif re.findall(r'^\s*A:\s+([0-9.]+)\b', query.stdout, re.M) != [str(args.listen)] or \
            re.search(r'^\s*(?:AAAA|CNAME):', query.stdout, re.M):
        raise ValueError(f'Prověřte existující DNS záznam {args.host} v zóně {zone}.')


def install(args):
    if samba_setting('server role').lower() != 'active directory domain controller':
        raise ValueError('Spusťte jako root přímo na řadiči Samba AD.')
    from samba.auth import system_session
    from samba.param import LoadParm
    from samba.samdb import SamDB

    config = LoadParm()
    config.load_default()
    db = SamDB(session_info=system_session(), lp=config)
    realm = dns_name(config.get('realm')).upper()
    if args.manage_dns and not args.host.endswith('.' + realm.lower()):
        raise ValueError(f'Pro automatický DNS záznam musí jméno brány končit .{realm.lower()}.')
    ldap_host = args.ldap_host or dns_name(config.get('netbios name') + '.' + realm)
    ldap_ca = args.ldap_ca or Path(config.get('private dir')) / config.get('tls cafile')
    key = args.key_file.read_text().strip()
    if not re.fullmatch(r'[a-f0-9]{64}', key):
        raise ValueError('Sdílený klíč musí obsahovat výstup openssl rand -hex 32.')

    if not args.configure_only:
        run('apt-get', 'update')
        run('apt-get', 'install', '-y', '--no-install-recommends', *MODULES,
            env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive'})
    cert = args.tls_cert.read_bytes()
    private_key = args.tls_key.read_bytes()
    run('openssl', 'x509', '-in', str(args.tls_cert), '-noout', '-checkend', '0', stdout=subprocess.DEVNULL)
    cert_info = run('openssl', 'x509', '-in', str(args.tls_cert), '-noout', '-checkhost', args.host, capture=True)
    if 'does match' not in cert_info or run('openssl', 'x509', '-in', str(args.tls_cert), '-pubkey', '-noout', capture=True) != \
            run('openssl', 'pkey', '-in', str(args.tls_key), '-passin', 'pass:', '-pubout', capture=True):
        raise ValueError('TLS certifikát musí platit pro DNS jméno brány a odpovídat privátnímu klíči.')
    ldap_ca_data = ldap_ca.read_bytes()
    central_ca = args.central_ca.read_bytes() if args.central_ca else None

    if not shutil.which('runuser'):
        raise ValueError('Chybí příkaz runuser z balíčku util-linux.')
    try:
        group = grp.getgrnam(NAME)
    except KeyError:
        run('useradd', '--system', '--user-group', '--no-create-home', '--shell', '/usr/sbin/nologin', NAME)
        group = grp.getgrnam(NAME)
    gid = group.gr_gid
    run('install', '-d', '-o', 'root', '-g', NAME, '-m', '0750', str(CONFIG))
    write(CONFIG / 'ldap-ca.pem', ldap_ca_data, gid=gid)
    write(CONFIG / 'krb5.conf', f'[libdefaults]\n    default_realm = {realm}\n    rdns = false\n'
                               f'    dns_canonicalize_hostname = false\n[realms]\n    {realm} = {{\n'
                               f'        kdc = {ldap_host}\n    }}\n', gid=gid)
    bind_dn, group_dn, ldap_url = configure_ad(db, args, gid, ldap_host)
    if args.manage_dns:
        configure_dns(args, ldap_host, realm.lower())

    write(CONFIG / 'tls.pem', cert, gid=gid)
    write(CONFIG / 'tls.key', private_key, gid=gid)
    write(CONFIG / 'central-key', key + '\n', gid=gid)
    if central_ca is not None:
        write(CONFIG / 'central-ca.pem', central_ca, gid=gid)
    else:
        (CONFIG / 'central-ca.pem').unlink(missing_ok=True)
    run('install', '-d', '-m', '0755', str(SCRIPTS))
    write(SCRIPTS / 'enroll.py', (SOURCE / 'enroll.py').read_bytes(), 0o755)

    # RFC 4514 DNs may contain backslashes or quotes. Preserve them in Apache's quoted arguments.
    def apache_quote(value):
        return value.replace('\\', '\\\\').replace('"', '\\"')

    httpd = Template((SOURCE / 'httpd.conf.in').read_text()).substitute(
        listen=f'{args.listen}:{args.port}', hostname=args.host, central=args.central,
        allowed=' '.join(str(network) for network in args.allow), realm=realm,
        ldap_url=apache_quote(f'{ldap_url}/{db.domain_dn()}?sAMAccountName?sub?'
                             '(&(objectClass=computer)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))'),
        bind_dn=apache_quote(bind_dn), group_dn=group_dn,
    )
    run('install', '-d', '-o', NAME, '-g', NAME, '-m', '0750', f'/run/{NAME}', f'/var/log/{NAME}')
    pending = CONFIG / 'httpd.conf.pending'
    write(pending, httpd, gid=gid)
    run('runuser', '-u', NAME, '--', 'env', f'KRB5_CONFIG={CONFIG / "krb5.conf"}',
        '/usr/sbin/apache2', '-t', '-f', str(pending))
    pending.replace(CONFIG / 'httpd.conf')
    write(Path('/etc/systemd/system') / f'{NAME}.service', (SOURCE / f'{NAME}.service').read_bytes(), 0o644)
    if not args.configure_only:
        run('systemctl', 'daemon-reload')
        run('systemctl', 'restart', NAME)
        run('systemctl', 'enable', NAME)
    port = f':{args.port}' if args.port != 443 else ''
    print(f'EnrollmentServer = https://{args.host}{port}\nGATEWAY_ENROLL_REALM = {realm}\nSkupina AD = {GROUP}')


if __name__ == '__main__':
    os.umask(0o077)
    try:
        args = args_from_cli()
        if os.geteuid() != 0:
            raise ValueError('Spusťte jako root přímo na řadiči Samba AD.')
        with open(f'/run/{NAME}-install.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            install(args)
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        sys.exit(f'Instalace brány skončila chybou. {error}')
