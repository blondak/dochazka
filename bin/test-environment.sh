#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

command=${1:-up}
if [[ "$command" == up && ! -f .env.test ]]; then
    (
        umask 077
        set -o noclobber
        {
            printf 'APP_IMAGE=dochazka:php-test\nWEB_IMAGE=dochazka:web-test\nTEST_PORT=8080\n'
            printf 'DB_PASSWORD=%s\n' "$(openssl rand -hex 32)"
            printf 'SETUP_TOKEN=%s\n' "$(openssl rand -hex 32)"
        } > .env.test
    )
fi

compose() {
    docker compose --project-name dochazka-test --env-file .env.test \
        -f compose.yaml -f deploy/compose.test.yaml "$@"
}

case "$command" in
    up)
        compose up -d --build --remove-orphans --wait --wait-timeout 90
        address=$(compose port web 80)
        printf 'Docházka: http://localhost:%s\nInstalační kód: SETUP_TOKEN v .env.test\n' "${address##*:}"
        ;;
    status) compose ps ;;
    stop) compose stop ;;
    logs) compose logs --tail 70 ;;
    *) printf 'Použití: bash bin/test-environment.sh [up|status|stop|logs]\n' >&2; exit 2 ;;
esac
