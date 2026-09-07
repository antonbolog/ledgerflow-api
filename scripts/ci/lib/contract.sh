#!/usr/bin/env bash
#
# Shared harness for the disposable-Compose contract scripts: a work deadline
# enforced on every external command, and a scoped teardown that always runs.
#
# Before sourcing, the caller sets CONTRACT_NAME, PROJECT, DEADLINE_CEILING, and
# optionally requested_deadline and a CRASH_CONTAINERS array of `compose run`
# container names. After sourcing it defines diagnose(), which cleanup() calls on
# a failing exit.

: "${CONTRACT_NAME:?contract lib: CONTRACT_NAME must be set before sourcing}"
: "${PROJECT:?contract lib: PROJECT must be set before sourcing}"
: "${DEADLINE_CEILING:?contract lib: DEADLINE_CEILING must be set before sourcing}"

# Overridable only downwards: a caller may shorten the budget, no env var may
# lengthen it past the ceiling.
requested_deadline=${requested_deadline:-$DEADLINE_CEILING}

case "$requested_deadline" in
    ''|*[!0-9]*) requested_deadline=$DEADLINE_CEILING ;;
esac

if [ "$requested_deadline" -lt 1 ] || [ "$requested_deadline" -gt "$DEADLINE_CEILING" ]; then
    requested_deadline=$DEADLINE_CEILING
fi

readonly DEADLINE_SECONDS="$requested_deadline"

# Fixed budgets: on the path that matters the work deadline has just expired, so
# the remaining overall budget is zero.
readonly CLEANUP_BUDGET_SECONDS=60
readonly DIAGNOSTIC_BUDGET_SECONDS=10

readonly DC=(docker compose -p "$PROJECT" -f compose.yaml --profile projection)

# The disposable project must never inherit the developer's project name from a
# local .env.
export COMPOSE_PROJECT_NAME="$PROJECT"

started_at=$(date +%s)
workdir=$(mktemp -d)

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

remaining_seconds() {
    local left=$((DEADLINE_SECONDS - ($(date +%s) - started_at)))

    if [ "$left" -lt 0 ]; then
        left=0
    fi

    printf '%s' "$left"
}

# Run a command with a hard wall-clock bound, returning 124 if it had to be
# killed. Runs in the background so this shell keeps its signal handlers live;
# SIGTERM then SIGKILL, aimed at the child only. Built from job control rather
# than GNU `timeout`, which macOS does not ship.
run_bounded() {
    local budget="$1"
    shift

    if [ "$budget" -le 0 ]; then
        return 124
    fi

    "$@" &
    local pid=$!
    local start
    start=$(date +%s)

    # 0.2s poll: crash steps must observe and `docker kill` inside the consumer's
    # 2s statement timeout, which a 1s tail would spend doing nothing.
    while kill -0 "$pid" 2>/dev/null; do
        if [ "$(( $(date +%s) - start ))" -ge "$budget" ]; then
            kill -TERM "$pid" 2>/dev/null || true
            sleep 2
            kill -KILL "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true

            return 124
        fi

        sleep 0.2
    done

    local status=0
    wait "$pid" || status=$?

    return "$status"
}

# Every external command goes through here, budgeted by what is left overall, so
# a single hung step ends the contract at the deadline instead of running forever.
guard() {
    local description="$1"
    shift

    local status=0
    run_bounded "$(remaining_seconds)" "$@" || status=$?

    if [ "$status" -eq 124 ]; then
        fail "the ${DEADLINE_SECONDS}s work deadline expired while: ${description}"
    fi

    return "$status"
}

# Catches expiry inside a polling loop promptly, and names the step rather than
# the last command it ran.
check_deadline() {
    local elapsed=$(($(date +%s) - started_at))

    if [ "$elapsed" -ge "$DEADLINE_SECONDS" ]; then
        fail "the ${CONTRACT_NAME} exceeded its ${DEADLINE_SECONDS}s work deadline at step: $1"
    fi
}

step() {
    check_deadline "$1"
    printf '==> %s\n' "$1"
}

# --------------------------------------------------------------------------
# Helpers against the disposable project only
# --------------------------------------------------------------------------

psql_disposable() {
    guard "psql: ${1:0:60}" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -tAc "$1"
}

artisan_disposable() {
    guard "artisan $*" "${DC[@]}" run --rm -T --no-deps app php artisan "$@"
}

dc() {
    local description="$1"
    shift

    guard "$description" "${DC[@]}" "$@"
}

# Poll until the command succeeds, or give up after $2 attempts one second apart.
await() {
    local description="$1" attempts="$2"
    shift 2

    local attempt=0

    while [ "$attempt" -lt "$attempts" ]; do
        if run_bounded "$(remaining_seconds)" "$@" >/dev/null 2>&1; then
            return 0
        fi

        attempt=$((attempt + 1))
        check_deadline "waiting for ${description}"
        sleep 1
    done

    fail "timed out after ${attempts}s waiting for ${description}"
}

await_query() {
    local description="$1" query="$2" expected="$3" attempts="$4"
    local attempt=0 actual=''

    while [ "$attempt" -lt "$attempts" ]; do
        actual=$(psql_disposable "$query" 2>/dev/null | tr -d '[:space:]' || true)

        if [ "$actual" = "$expected" ]; then
            return 0
        fi

        attempt=$((attempt + 1))
        check_deadline "waiting for ${description}"
        sleep 1
    done

    fail "timed out after ${attempts}s waiting for ${description} (expected '${expected}', last saw '${actual}')"
}

query_scalar() {
    psql_disposable "$1" | tr -d '[:space:]'
}

assert_query() {
    local description="$1" query="$2" expected="$3"
    local actual

    actual=$(query_scalar "$query")

    if [ "$actual" != "$expected" ]; then
        fail "${description}: expected '${expected}', got '${actual}'"
    fi
}

# --------------------------------------------------------------------------
# Teardown
# --------------------------------------------------------------------------

# Always, including a failed step, an interrupt and the deadline: a disposable
# project that survived would hold its containers and volumes until someone
# noticed, and the next run would start against state it did not create.
#
# Every stage is attempted even after an earlier one fails, and a teardown that
# did not complete fails a passing suite while leaving a failing one its cause.
cleanup() {
    local status=$?
    local teardown=0

    trap - EXIT

    if [ "$status" -ne 0 ] && declare -F diagnose >/dev/null; then
        # A failing diagnostic must not skip the teardown it precedes.
        diagnose || true
    fi

    # `compose down` does not know about `run` containers, and a leaked one holds
    # the name the next run needs; only one that still exists is owed a removal.
    for name in ${CRASH_CONTAINERS[@]+"${CRASH_CONTAINERS[@]}"}; do
        if ! run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" \
            docker inspect --type container "$name" >/dev/null 2>&1; then
            continue
        fi

        if ! run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" docker rm -f "$name" >/dev/null 2>&1; then
            printf 'ERROR: teardown failed to remove crash container %s\n' "$name" >&2
            teardown=1
        fi
    done

    printf '==> removing the disposable project\n'
    if ! run_bounded "$CLEANUP_BUDGET_SECONDS" \
        "${DC[@]}" down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1; then
        printf 'ERROR: teardown failed to remove the disposable project %s\n' "$PROJECT" >&2
        teardown=1
    fi

    if ! rm -rf "$workdir" 2>/dev/null; then
        printf 'ERROR: teardown failed to remove the fixture directory %s\n' "$workdir" >&2
        teardown=1
    fi

    if [ "$status" -eq 0 ] && [ "$teardown" -ne 0 ]; then
        printf 'ERROR: %s passed but its mandatory teardown did not complete\n' "$CONTRACT_NAME" >&2
        status=1
    fi

    exit "$status"
}

trap cleanup EXIT INT TERM
