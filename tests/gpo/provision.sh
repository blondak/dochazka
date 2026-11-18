#!/usr/bin/env bash
set -eu
umask 077
dir=/run/enroll-test
test ! -f /var/lib/samba/private/sam.ldb
mv /etc/samba/smb.conf /tmp/package-smb.conf
samba-tool domain provision --realm=ATTEND.TEST --domain=ATTEND --server-role=dc --host-name=dc --use-rfc2307 \
    --adminpass="Az!9$(openssl rand -hex 24)" --option='acl_xattr:security_acl_name=user.NTACL' >/dev/null
samba-tool user create gateway --random-password
samba-tool spn add HTTP/enroll.attend.test gateway
samba-tool domain exportkeytab "$dir/gpo_keytab" --principal=HTTP/enroll.attend.test
chown www-data "$dir/gpo_keytab"
printf 'Az9!%s\n' "$(openssl rand -hex 32)" > "$dir/gpo_bind_password"
samba-tool user create lookup "$(cat "$dir/gpo_bind_password")"
samba-tool group add Enrollable
for account in 'PILOT' 'UNLISTED'; do
    samba-tool computer create "$account"
    samba-tool user setpassword --filter="(sAMAccountName=$account\$)" --random-password
    samba-tool user enable --filter="(sAMAccountName=$account\$)"
    samba-tool domain exportkeytab "/tmp/$account.keytab" --principal="$account\$"
done
samba-tool group addmembers Enrollable 'PILOT$'
samba-tool group addmembers Enrollable lookup
samba --foreground --no-process-group &
dc=$!
for attempt in $(seq 1 90); do
    if test -s /var/lib/samba/private/tls/ca.pem && kinit -k -t /tmp/PILOT.keytab 'PILOT$'; then
        cp /var/lib/samba/private/tls/ca.pem "$dir/gpo_ldap_ca"
        chmod 644 "$dir/gpo_ldap_ca"
        touch "$dir/ready"
        break
    fi
    sleep 1
done
test -f "$dir/ready"
wait "$dc"
