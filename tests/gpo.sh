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
    if (( $? != 0 )); then compose logs --tail 15 dc enroll app >&2 || true; fi
    compose down --volumes --rmi local
    if [[ $build_gateway == true ]]; then docker image rm "$GPO_IMAGE" >/dev/null || true; fi
}
trap cleanup EXIT
compose build dc
if [[ $build_gateway == true ]]; then compose build enroll; fi
compose up -d --no-build --wait --wait-timeout 150
compose exec -T db psql -v ON_ERROR_STOP=1 -U dochazka -d dochazka -c "INSERT INTO employee(number,name,login,password_hash,roles,attendance_enabled,team,sid) VALUES ('pilot','Pilot','pilot','!',jsonb_build_array('employee'::text),true,'Pilot','S-1-5-21-1-2-3-44')"
compose exec -T dc python3 /opt/enrollment.py
compose exec -T db psql -v ON_ERROR_STOP=1 -U dochazka -d dochazka -c "UPDATE device SET revoked=TRUE WHERE domain_principal='PILOT\$@ATTEND.TEST'"
compose exec -T dc python3 /opt/enrollment.py revoked
echo 'Samba AD, Kerberos enrollment, group authorization and revocation OK.'
