#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php_bin=${PHP_BIN:-php}
coverage_tmp=$(mktemp -d)
trap 'rm -rf "$coverage_tmp"' EXIT
mkdir "$coverage_tmp/ini" "$coverage_tmp/results"
export ATTENDANCE_COVERAGE_DIR="$coverage_tmp/results"
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-}:$coverage_tmp/ini"
export PHP_TEST_ROUTER="$PWD/tests/coverage/router.php"
{
    if [[ -n "${PCOV_EXTENSION:-}" ]]; then
        printf 'extension="%s"\n' "$PCOV_EXTENSION"
    fi
    printf 'pcov.enabled=1\npcov.directory="%s/src"\nauto_prepend_file="%s/tests/coverage/record.php"\n' "$PWD" "$PWD"
} > "$coverage_tmp/ini/99-coverage.ini"
"$php_bin" -r 'if (!extension_loaded("pcov")) { fwrite(STDERR, "Enable PCOV for the test PHP binary.\n"); exit(1); }'
PCOV_CATALOG=1 "$php_bin" tests/coverage/catalog.php
"$php_bin" bin/console api:openapi
"$php_bin" tests/timesheet.php
python3 tests/workflow.py
python3 tests/coverage/report.py "$ATTENDANCE_COVERAGE_DIR"
