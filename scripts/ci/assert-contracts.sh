#!/usr/bin/env bash

set -euo pipefail

backup=.env.ci-contract-backup
modified=.env.ci-contract-modified
marker_created=false

restore_environment() {
    if [ ! -f "$backup" ]; then
        return
    fi

    mv -f "$backup" .env
    docker compose up -d --force-recreate --wait app
}

cleanup() {
    local status=$?

    trap - EXIT

    if ! restore_environment; then
        printf 'ERROR: could not restore the application environment\n' >&2
        status=1
    fi

    if [ -f "$modified" ]; then
        rm -f "$modified"
    fi

    if [ "$marker_created" = true ]; then
        if ! docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -q -c \
            'DROP TABLE ci_isolation_marker;'; then
            printf 'ERROR: could not remove ci_isolation_marker\n' >&2
            status=1
        fi
    fi

    exit "$status"
}

trap cleanup EXIT

assert_refuses() {
    local target="$1"
    local expected="$2"
    local output

    if output=$(make "$target" 2>&1); then
        printf 'ERROR: make %s succeeded without confirmation\n%s\n' "$target" "$output" >&2
        return 1
    fi

    if ! grep -q -- "$expected" <<<"$output"; then
        printf 'ERROR: make %s refused without the documented message %s\n%s\n' \
            "$target" "$expected" "$output" >&2
        return 1
    fi

    printf 'make %s refused as documented\n' "$target"
}

assert_guard_refuses() {
    local target="$1"
    local output
    local expected='REFUSING: this target may only touch local project databases'

    if output=$(make "$target" 2>&1); then
        printf 'ERROR: make %s ran against a non-allowlisted database\n%s\n' \
            "$target" "$output" >&2
        return 1
    fi

    if ! grep -q -- "$expected" <<<"$output"; then
        printf 'ERROR: make %s failed without exercising the database guard\n%s\n' \
            "$target" "$output" >&2
        return 1
    fi

    printf 'make %s refused a non-allowlisted host\n' "$target"
}

assert_refuses destroy 'CONFIRM=destroy'
assert_refuses db-reset 'CONFIRM=db-reset'

[ ! -e "$backup" ] || {
    printf 'ERROR: stale CI contract backup exists: %s\n' "$backup" >&2
    exit 1
}
[ ! -e "$modified" ] || {
    printf 'ERROR: stale CI contract temporary file exists: %s\n' "$modified" >&2
    exit 1
}

cp .env "$backup"
sed 's/^DB_HOST=postgres$/DB_HOST=db.invalid.example/' .env >"$modified"
mv -f "$modified" .env
docker compose up -d --force-recreate --wait app

assert_guard_refuses db-migrate
assert_guard_refuses db-seed

restore_environment

existing_marker=$(docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -tAc \
    "SELECT to_regclass('public.ci_isolation_marker') IS NOT NULL;" | tr -d '[:space:]')

if [ "$existing_marker" != 'f' ]; then
    printf 'ERROR: ci_isolation_marker already exists; refusing to overwrite it\n' >&2
    exit 1
fi

docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -q -c \
    "CREATE TABLE ci_isolation_marker(note text); INSERT INTO ci_isolation_marker VALUES ('intact');"
marker_created=true

make check

found=$(docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -tAc \
    'SELECT note FROM ci_isolation_marker;' | tr -d '[:space:]')

if [ "$found" != 'intact' ]; then
    printf 'ERROR: make check mutated the development database\n' >&2
    exit 1
fi

printf 'Development database remained untouched by make check\n'
