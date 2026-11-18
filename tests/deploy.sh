#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

project="dochazka-smoke-$(openssl rand -hex 5)"
export APP_IMAGE="${1:?Pass the locally built PHP-FPM image to test}"
export WEB_IMAGE="${2:?Pass the locally built Nginx image to test}"
export TEST_PORT=0
export DB_PASSWORD SETUP_TOKEN
DB_PASSWORD=$(openssl rand -hex 32)
SETUP_TOKEN=$(openssl rand -hex 32)

compose() {
    docker compose --project-name "$project" --env-file /dev/null -f compose.yaml -f deploy/compose.test.yaml "$@"
}

cleanup() {
    local status=$?
    if (( status != 0 )); then
        compose logs --tail 20 || true
    fi
    compose down --volumes || true
}
trap cleanup EXIT

compose up -d --no-build --wait --wait-timeout 90
address=$(compose port web 80)
python3 tests/workflow.py --deployment "${address##*:}"

# The workflow populated this disposable cluster with a medical proof and versioned exports.
contents() {
    compose exec -T db psql -XAt -v ON_ERROR_STOP=1 -U dochazka -d "$1" <<'SQL'
SELECT 'export', month, version::text, encode(sha256(convert_to(content, 'UTF8')), 'hex'), fingerprint
FROM export
UNION ALL
SELECT 'proof', request_id::text, r.employee::text, encode(sha256(decode(jpeg_base64, 'base64')), 'hex'), r.status
FROM medical_proof JOIN request r ON r.id = request_id
ORDER BY 1, 2, 3;
SQL
}

original=$(contents dochazka)
[[ "$original" == *'export|'* && "$original" == *'proof|'* ]]
compose exec -T db createdb -U dochazka -T template0 restore_verification
compose exec -T db pg_dump -U dochazka -Fc dochazka |
    compose exec -T db pg_restore -U dochazka --single-transaction --exit-on-error --no-owner --no-acl -d restore_verification
restored=$(contents restore_verification)
if [[ "$restored" != "$original" ]]; then
    echo "Obnovené exporty nebo propustky se liší od původních dat." >&2
    exit 1
fi
echo "PostgreSQL restore, export versions and medical proof OK"
