#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
project="dochazka-gpo-$(openssl rand -hex 5)"
export DB_PASSWORD
DB_PASSWORD=$(openssl rand -hex 32)
build_gateway=false
if [[ -z ${GPO_IMAGE:-} ]]; then
    export GPO_IMAGE="$project-enroll"
    build_gateway=true
fi
compose() {
    docker compose --project-name "$project" --env-file /dev/null -f compose.yaml -f tests/gpo/compose.yaml "$@"
}
cleanup() {
    if (( $? != 0 )); then
        compose logs --tail 15 dc enroll app central-https >&2 || true
        compose exec -T dc tail -n 25 /var/log/dochazka-gateway/error.log >&2 || true
    fi
    compose down --volumes --rmi local
    if [[ $build_gateway == true ]]; then docker image rm "$GPO_IMAGE" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
compose build dc
if [[ $build_gateway == true ]]; then compose build enroll; fi
compose up -d --no-build --wait --wait-timeout 150
compose exec -T db psql -v ON_ERROR_STOP=1 -U dochazka -d dochazka -c "INSERT INTO employee(number,name,login,password_hash,roles,attendance_enabled,team,sid) VALUES ('pilot','Pilot','pilot','!',jsonb_build_array('employee'::text),true,'Pilot','S-1-5-21-1-2-3-44')"
compose exec -T dc python3 /opt/enrollment.py
configure_native() {
    compose exec -T dc bash -ec 'ip=$(hostname -i); python3 /opt/debian/install.py --configure-only \
        --host gateway.attend.test --listen "$ip" --port 8443 --allow "${ip%.*}.0/24" \
        --central https://central.test --central-ca /run/enroll-test/central.pem \
        --tls-cert /tmp/gateway.pem --tls-key /tmp/gateway.key --key-file /run/enroll-test/central-key \
        --pilot PILOT --manage-dns'
}
configure_native
configure_native
compose exec -T -d dc runuser -u dochazka-gateway -- env KRB5_CONFIG=/etc/dochazka-gateway/krb5.conf \
    /usr/sbin/apache2 -f /etc/dochazka-gateway/httpd.conf -DFOREGROUND
compose exec -T dc python3 /opt/enrollment.py native
compose exec -T db psql -v ON_ERROR_STOP=1 -U dochazka -d dochazka -c "UPDATE device SET revoked=TRUE WHERE domain_principal='PILOT\$@ATTEND.TEST'"
compose exec -T dc python3 /opt/enrollment.py revoked
compose exec -T dc python3 /opt/enrollment.py native-revoked
echo 'Samba AD, native HTTPS gateway, Kerberos group authorization, central gateway signing and revocation OK.'
