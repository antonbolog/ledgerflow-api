#!/usr/bin/env bash

set -euo pipefail

backup=.env.ci-contract-backup
modified=.env.ci-contract-modified
marker_created=false
injection_marker=$(mktemp -u "${TMPDIR:-/tmp}/ledgerflow-injection-marker.XXXXXX")
vulnerable_makefile=$(mktemp "${TMPDIR:-/tmp}/ledgerflow-vulnerable.XXXXXX")

restore_environment() {
    if [ ! -f "$backup" ]; then
        return 0
    fi

    # Explicit: cleanup calls this under `if !`, which disables errexit here, so a
    # silent fall-through would report the recreate's status instead.
    mv -f "$backup" .env || return 1

    docker compose up -d --force-recreate --wait app
}

cleanup() {
    local status=$?

    trap - EXIT INT TERM

    if ! restore_environment; then
        printf 'ERROR: could not restore the application environment\n' >&2
        status=1
    fi

    if [ -f "$modified" ]; then
        rm -f "$modified"
    fi

    rm -f "$injection_marker" "$vulnerable_makefile"

    if [ "$marker_created" = true ]; then
        if ! docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -q -c \
            'DROP TABLE ci_isolation_marker;'; then
            printf 'ERROR: could not remove ci_isolation_marker\n' >&2
            status=1
        fi
    fi

    exit "$status"
}

# EXIT alone does not run on Ctrl-C, and this script mutates the real .env and the
# real development database. Same signal set as scripts/ci/lib/contract.sh.
trap cleanup EXIT INT TERM

assert_refuses() {
    local target="$1"
    local expected="$2"
    shift 2
    local output

    if output=$(make "$target" "$@" 2>&1); then
        printf 'ERROR: make %s %s succeeded without confirmation\n%s\n' \
            "$target" "$*" "$output" >&2
        return 1
    fi

    if ! grep -q -- "$expected" <<<"$output"; then
        printf 'ERROR: make %s %s refused without the documented message %s\n%s\n' \
            "$target" "$*" "$expected" "$output" >&2
        return 1
    fi

    printf 'make %s %s refused as documented\n' "$target" "$*"
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

# Every case below is a refusal, so no token is minted and no row is deleted. The nil
# UUID is the only one reaching PostgreSQL, and deletes nothing: the statement removes
# a row only while exactly one matches.
readonly ABSENT_PAYMENT_ID=00000000-0000-0000-0000-000000000000

assert_refuses demo-corrupt 'never picks a payment for you'
assert_refuses demo-corrupt 'not a canonical UUID' \
    PAYMENT_ID=not-a-uuid CONFIRM=corrupt-projection
assert_refuses demo-corrupt 'CONFIRM=corrupt-projection' \
    "PAYMENT_ID=$ABSENT_PAYMENT_ID"
assert_refuses demo-corrupt 'expected exactly one matching payment_details row, matched 0, removed 0' \
    "PAYMENT_ID=$ABSENT_PAYMENT_ID" CONFIRM=corrupt-projection
assert_refuses demo-token 'must name a seeded fixture identity' \
    EMAIL=nobody@example.test

# Each case proves its payload is live against the pre-fix interpolation, then
# requires the real target to refuse without running it.
cat >"$vulnerable_makefile" <<'VULNERABLE'
email:
	@case " owner@northwind.test " in *" $(EMAIL) "*) ;; *) echo REFUSING; exit 1;; esac
payment_id:
	@printf '%s' '$(PAYMENT_ID)' | grep -Eq '^unmatchable$$' || { echo REFUSING; exit 1; }
confirm:
	@if [ "$(CONFIRM)" != "corrupt-projection" ]; then echo REFUSING; exit 1; fi
VULNERABLE

assert_injection_refused() {
    local pattern="$1" target="$2" expected="$3"
    shift 3

    rm -f "$injection_marker"

    make -f "$vulnerable_makefile" "$pattern" "$@" >/dev/null 2>&1 || true

    if [ ! -e "$injection_marker" ]; then
        printf 'ERROR: the %s payload does not reach shell source even in the pre-fix pattern; this assertion would prove nothing\n' \
            "$pattern" >&2
        return 1
    fi

    rm -f "$injection_marker"

    assert_refuses "$target" "$expected" "$@" >/dev/null || return 1

    if [ -e "$injection_marker" ]; then
        printf 'ERROR: make %s executed an injected command\n' "$target" >&2
        return 1
    fi

    printf 'make %s refused the %s injection payload without executing it\n' "$target" "$pattern"
}

# Escapes the case arm, not a quote: the pre-fix check interpolated into a glob.
assert_injection_refused email demo-token 'must name a seeded fixture identity' \
    "EMAIL=zz\"*) ;; *) touch $injection_marker ;; esac #"
assert_injection_refused payment_id demo-corrupt 'not a canonical UUID' \
    "PAYMENT_ID='; touch $injection_marker; '" CONFIRM=corrupt-projection
assert_injection_refused confirm demo-corrupt 'CONFIRM=corrupt-projection' \
    "PAYMENT_ID=$ABSENT_PAYMENT_ID" "CONFIRM=\"; touch $injection_marker; \""

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

# assert_local_db resolves DB_URL before DB_HOST, so the sed above does not prove the
# effective host changed. Ask the container which source wins; host only, never the URL.
effective_host=$(docker compose exec -T app php -r '
    $url = getenv("DB_URL") ?: "";
    if ($url !== "") { $parts = parse_url($url); echo "DB_URL:", $parts["host"] ?? ""; }
    else { echo "DB_HOST:", getenv("DB_HOST") ?: ""; }
' | tr -d '[:space:]')

if [ "$effective_host" != 'DB_HOST:db.invalid.example' ]; then
    printf 'ERROR: could not point the effective database host at an unreachable name (resolved %s)\n' \
        "$effective_host" >&2
    printf '  The guard assertions are skipped rather than run against an allowlisted database.\n' >&2
    printf '  A checkout that resolves the database through DB_URL is not supported by this contract.\n' >&2
    exit 1
fi

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
    printf 'ERROR: make check did not leave ci_isolation_marker intact\n' >&2
    exit 1
fi

printf 'make check left the ci_isolation_marker row intact; it neither dropped nor reset the development database\n'
