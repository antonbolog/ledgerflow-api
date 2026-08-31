#!/usr/bin/env bash
#
# The scheduler + Horizon pair, proved end to end against real PostgreSQL and
# real Redis, in a Compose project that exists only for the length of this run.
#
# A DISPOSABLE PROJECT, NOT THE DEVELOPMENT STACK. Every container, network and
# volume Compose creates is namespaced by project name, so `-p
# ledgerflow-projection-contract` gets its own `postgres-data` and `redis-data`
# and can be torn down with `down --volumes` without any possibility of reaching
# the developer's database. Nothing here runs against `ledgerflow`.
#
# IT REUSES THE DEVELOPMENT IMAGE rather than building its own. compose.yaml
# names the image explicitly, so this project starts the bytes `make install`
# already built; a rebuild here would add minutes and prove nothing about the
# code under test.
#
# BOUNDED EVERYWHERE, AND ENFORCED RATHER THAN COOPERATIVE. Every external
# command runs through run_bounded(), whose budget is whatever is LEFT of the
# WORK deadline — so a single wedged `docker compose exec` cannot outlive the
# contract the way a between-steps-only check would allow. Polling loops keep
# their own attempt limits on top of that.
#
# THE DEADLINE BOUNDS THE WORK, NOT THE WHOLE WALL CLOCK. Guaranteed scoped
# cleanup takes precedence over a flat cap: once the work stops, the obligatory
# teardown adds its own separately bounded tail. See CLEANUP_BUDGET_SECONDS.
#
# NO GNU `timeout`. It is not a declared prerequisite and macOS does not ship it,
# so the bound is built from POSIX job control: run the command in the
# background, poll `kill -0`, then SIGTERM and SIGKILL it. That also avoids the
# trap that makes a naive watchdog useless — bash defers a signal handler until
# the current FOREGROUND command returns, so signalling the script itself would
# do nothing at all while it is blocked in the hung command.
#
# QUIET ON SUCCESS. Diagnostics — container state, recent logs from both workers,
# the outbox row — are printed only when a step fails, so a green run says one
# line per step and a red one says everything needed to explain it.

set -euo pipefail

readonly PROJECT=ledgerflow-projection-contract

# THE WORK DEADLINE. Overridable ONLY DOWNWARDS: the accepted contract is "no
# greater than 180 seconds", so a caller may shorten the budget — the self-test
# below does — but an environment variable must not be able to lengthen it past
# what was agreed. It bounds the contract's WORK; the teardown tail below is
# bounded separately.
requested_deadline=${PROJECTION_CONTRACT_DEADLINE_SECONDS:-180}

case "$requested_deadline" in
    ''|*[!0-9]*) requested_deadline=180 ;;
esac

if [ "$requested_deadline" -lt 1 ] || [ "$requested_deadline" -gt 180 ]; then
    requested_deadline=180
fi

readonly DEADLINE_SECONDS="$requested_deadline"

# Cleanup and diagnostics get their own FIXED budgets rather than the remaining
# one, which is zero by definition on the path that matters most: the deadline
# having just expired.
#
# WHAT THE DEADLINE GUARANTEES, PRECISELY. The contract's WORK stops at
# DEADLINE_SECONDS — no step, and no command inside a step, can run past it. The
# obligatory teardown then adds a bounded tail: at most four diagnostics at
# DIAGNOSTIC_BUDGET_SECONDS each plus one CLEANUP_BUDGET_SECONDS `down`. The two
# obligations pull against each other — "stop at 180" and "always clean up" — and
# this is the split: the deadline bounds the work, and the tail is bounded
# separately rather than being allowed to run free.
readonly CLEANUP_BUDGET_SECONDS=60
readonly DIAGNOSTIC_BUDGET_SECONDS=10

readonly DC=(docker compose -p "$PROJECT" -f compose.yaml --profile projection)

# The disposable project must never inherit the developer's port publishing or
# project name from a local .env.
export COMPOSE_PROJECT_NAME="$PROJECT"

started_at=$(date +%s)

# --------------------------------------------------------------------------
# Cleanup and diagnostics
# --------------------------------------------------------------------------

diagnose() {
    printf '\n===== projection-contract diagnostics =====\n' >&2
    # EACH DIAGNOSTIC IS ITSELF BOUNDED. They run after the deadline has already
    # expired, against a daemon or a container that may be exactly what wedged, so
    # an unbounded `logs` here would turn a terminated contract back into a hang —
    # and the teardown below would never run.
    printf '\n--- container state ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" ps --all >&2 2>&1 || true
    printf '\n--- scheduler (last 40) ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" logs --tail 40 scheduler >&2 2>&1 || true
    printf '\n--- horizon (last 40) ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" logs --tail 40 horizon >&2 2>&1 || true
    # DIRECT, NOT THROUGH psql_disposable(): that helper is guarded, and guard()
    # calls fail() on expiry — which would exit the script from inside cleanup and
    # skip the teardown these diagnostics precede.
    printf '\n--- payment_outbox ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -c \
        "select event_id, publish_count, projection_completed_at, projection_failure_count,
                projection_failure_generation, last_projection_error, exhausted_at
           from payment_outbox" >&2 2>&1 || true
    printf '\n--- payment_details / receipts ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -c \
        "select (select count(*) from payment_details) as details,
                (select count(*) from payment_projector_receipts) as receipts" >&2 2>&1 || true
    printf '\n===========================================\n' >&2
}

# ON EVERY EXIT, INCLUDING A FAILED STEP, AN INTERRUPT AND THE DEADLINE. A
# disposable project that survived a failure would hold its volumes and its
# containers until someone noticed, and the next run would then start against
# state it did not create.
cleanup() {
    local status=$?

    trap - EXIT

    if [ "$status" -ne 0 ]; then
        diagnose
    fi

    # BOUNDED BY ITS OWN FIXED BUDGET, because the remaining overall budget is
    # zero precisely when this matters most. Scoped to $PROJECT, so it can only
    # ever remove the disposable project's containers, networks and volumes.
    printf '==> removing the disposable project\n'
    run_bounded "$CLEANUP_BUDGET_SECONDS" \
        "${DC[@]}" down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true

    exit "$status"
}

trap cleanup EXIT INT TERM

fail() {
    printf 'ERROR: %s\n' "$1" >&2
    exit 1
}

# Seconds left of the overall deadline, never negative.
remaining_seconds() {
    local left=$((DEADLINE_SECONDS - ($(date +%s) - started_at)))

    if [ "$left" -lt 0 ]; then
        left=0
    fi

    printf '%s' "$left"
}

# Run a command with a hard wall-clock bound, returning 124 if it had to be killed.
#
# THE COMMAND RUNS IN THE BACKGROUND so this shell keeps control: a foreground
# child would block bash entirely, including its signal handlers, which is
# exactly why signalling the script from a watchdog does not work.
#
# SIGTERM FIRST, THEN SIGKILL. A `docker compose` client asked to stop politely
# closes its connection and leaves the daemon's own work consistent; SIGKILL is
# the floor for a client that ignores it. Both are aimed at the child only,
# never at a process group, so nothing outside this script can be signalled.
run_bounded() {
    local budget="$1"
    shift

    if [ "$budget" -le 0 ]; then
        return 124
    fi

    "$@" &
    local pid=$!
    local waited=0

    while kill -0 "$pid" 2>/dev/null; do
        if [ "$waited" -ge "$budget" ]; then
            kill -TERM "$pid" 2>/dev/null || true
            sleep 2
            kill -KILL "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true

            return 124
        fi

        sleep 1
        waited=$((waited + 1))
    done

    local status=0
    wait "$pid" || status=$?

    return "$status"
}

# Every external command goes through here, budgeted by what is LEFT overall.
# A single hung step therefore ends the contract at the deadline instead of
# running forever, and the EXIT trap still fires with diagnostics and teardown.
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

# A SECOND, CHEAPER CHECK ON TOP OF guard(). Every external command is already
# bounded by the remaining budget, so this cannot be the only enforcement — but
# it is what catches expiry inside a polling loop promptly, and what gives the
# failure the name of the STEP rather than of the last command it ran.
check_deadline() {
    local elapsed=$(($(date +%s) - started_at))

    if [ "$elapsed" -ge "$DEADLINE_SECONDS" ]; then
        fail "the projection contract exceeded its ${DEADLINE_SECONDS}s work deadline at step: $1"
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

# Compose itself, bounded. `up`, `stop` and `start` are the commands most able to
# wedge, because they wait on the daemon rather than on this script.
dc() {
    local description="$1"
    shift

    guard "$description" "${DC[@]}" "$@"
}

# Poll until the command succeeds, or give up after $2 attempts one second apart.
# BOUNDED, and it reports which condition it was waiting for.
await() {
    local description="$1" attempts="$2"
    shift 2

    local attempt=0

    while [ "$attempt" -lt "$attempts" ]; do
        # Bounded per attempt as well as per loop: an `exec` into a wedged
        # container would otherwise stall a loop that counts only its own turns.
        if run_bounded "$(remaining_seconds)" "$@" >/dev/null 2>&1; then
            return 0
        fi

        attempt=$((attempt + 1))
        check_deadline "waiting for ${description}"
        sleep 1
    done

    fail "timed out after ${attempts}s waiting for ${description}"
}

# Poll until a scalar query returns the expected value.
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

assert_query() {
    local description="$1" query="$2" expected="$3"
    local actual

    actual=$(psql_disposable "$query" | tr -d '[:space:]')

    if [ "$actual" != "$expected" ]; then
        fail "${description}: expected '${expected}', got '${actual}'"
    fi
}

# --------------------------------------------------------------------------
# 0. A clean slate
# --------------------------------------------------------------------------

step 'removing any leftover disposable project'
run_bounded "$CLEANUP_BUDGET_SECONDS" \
    "${DC[@]}" down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true

# THE SELF-TEST, and the only fault this script can inject into itself.
#
# It exists so the enforced deadline has non-vacuous evidence: a step that hangs
# for ten minutes must be terminated at the budget and the disposable project must
# still be torn down. Guarded by an environment variable and placed before
# anything is created, so a normal run never reaches it and never waits for it.
#
# Run it with a short budget, which is the only direction the deadline may move:
#   PROJECTION_CONTRACT_DEADLINE_SECONDS=15 PROJECTION_CONTRACT_SELF_TEST=1 \
#     ./scripts/ci/projection-contract.sh
if [ "${PROJECTION_CONTRACT_SELF_TEST:-0}" = '1' ]; then
    step 'SELF-TEST: hanging deliberately, to prove the deadline terminates it'
    guard 'the deliberate self-test hang' sleep 600
    fail 'the self-test hang was not terminated by the deadline'
fi

step 'asserting the development image is already built'
guard 'inspecting the development image' \
    docker image inspect "${LEDGERFLOW_APP_IMAGE:-ledgerflow-app:latest}" >/dev/null 2>&1 \
    || fail 'the development image does not exist; run `make install` first'

# --------------------------------------------------------------------------
# 1. No scheduler-only activation path
# --------------------------------------------------------------------------

step 'asserting no Make target starts the scheduler without the consumer'

# A STATIC CHECK, AND IT BELONGS HERE rather than in a unit test: the thing being
# guarded is the operator interface, and the Makefile is that interface.
if make -n relay-start >/dev/null 2>&1; then
    fail 'make relay-start still exists; the scheduler-only activation path was not removed'
fi

projection_start_plan=$(make -n projection-start 2>&1)

grep -q 'scheduler' <<<"$projection_start_plan" \
    || fail 'make projection-start does not start the scheduler'
grep -q 'horizon' <<<"$projection_start_plan" \
    || fail 'make projection-start does not start Horizon'

# --------------------------------------------------------------------------
# 2. Infrastructure, then schema, then the pair
# --------------------------------------------------------------------------

step 'starting isolated PostgreSQL and Redis'
dc 'starting postgres and redis' up -d --wait --wait-timeout 60 postgres redis >/dev/null

step 'migrating and seeding the disposable database'
artisan_disposable migrate --force >/dev/null
artisan_disposable db:seed --force >/dev/null

merchant_id=$(psql_disposable 'select min(id) from merchants')
[ -n "$merchant_id" ] || fail 'the disposable database has no merchant to own a payment'

step 'starting the scheduler and Horizon together'
dc 'starting the projection pair' up -d scheduler horizon >/dev/null

# PAIRED STARTUP, PROVED BY BOTH HEALTHCHECKS. Horizon registers its master
# supervisor within seconds; the scheduler's first tick can wait for a minute
# boundary, which is why its budget is far larger. Both are bounded.
await 'Horizon to report itself running' 40 \
    "${DC[@]}" exec -T horizon php artisan horizon:status
await 'the scheduler heartbeat to go fresh' 100 \
    "${DC[@]}" exec -T scheduler php artisan payments:outbox:status

# --------------------------------------------------------------------------
# 3. Backlog while the consumer is stopped
# --------------------------------------------------------------------------

step 'stopping only the consumer, to build a backlog the relay cannot drain'

# STOPPED HERE, NOT STARTED ALONE. There is no activation path that brings the
# scheduler up without Horizon; this is a fault injected into an already-paired
# runtime, which is the state an operator actually meets when a worker dies.
dc 'stopping the consumer' stop horizon >/dev/null

event_id=$(psql_disposable "select gen_random_uuid()")
payment_id=$(psql_disposable "select gen_random_uuid()")

psql_disposable "
    insert into payment_outbox (
        event_id, event_type, schema_version, aggregate_uuid, aggregate_version,
        payload, occurred_at, available_at, created_at, updated_at
    ) values (
        '${event_id}', 'payments.payment_initiated', 1, '${payment_id}', 1,
        json_build_object(
            'eventId', '${event_id}',
            'paymentId', '${payment_id}',
            'merchantId', ${merchant_id},
            'amountMinor', 1250,
            'currency', 'USD',
            'occurredAt', '2026-08-21T10:11:12.123456+00:00'
        )::jsonb,
        '2026-08-21T10:11:12.123456+00:00', now(), now(), now()
    )" >/dev/null

# THE RELAY PUBLISHES ANYWAY: publication is the scheduler's job and does not
# depend on a consumer existing.
await_query 'the relay to publish the envelope' \
    "select publish_count from payment_outbox where event_id = '${event_id}'" '1' 40

# AND NOTHING IS PROJECTED, because nothing is draining the queue.
assert_query 'a stopped consumer must not complete the projection' \
    "select coalesce(projection_completed_at::text, 'null') from payment_outbox where event_id = '${event_id}'" \
    'null'
assert_query 'a stopped consumer must not create a read row' \
    'select count(*) from payment_details' '0'

# THE CENTRAL CLAIM OF THIS STEP: a backlog is not a failure. Queue wait is
# explicitly non-countable, so an outage of any length must leave the poison
# budget untouched — otherwise a stopped worker would eventually exhaust every
# envelope it never saw.
assert_query 'backlog must not consume the poison budget' \
    "select projection_failure_count from payment_outbox where event_id = '${event_id}'" '0'
assert_query 'backlog must not exhaust the envelope' \
    "select coalesce(exhausted_at::text, 'null') from payment_outbox where event_id = '${event_id}'" \
    'null'
assert_query 'backlog must not record a projection error' \
    "select coalesce(last_projection_error, 'null') from payment_outbox where event_id = '${event_id}'" \
    'null'

# --------------------------------------------------------------------------
# 4. Restart convergence
# --------------------------------------------------------------------------

step 'restarting the consumer and waiting for the backlog to drain'
dc 'restarting the consumer' start horizon >/dev/null

await 'Horizon to report itself running again' 40 \
    "${DC[@]}" exec -T horizon php artisan horizon:status

await_query 'the projection to complete after restart' \
    "select count(*) from payment_outbox
      where event_id = '${event_id}' and projection_completed_at is not null" '1' 60

assert_query 'exactly one read row' 'select count(*) from payment_details' '1'
assert_query 'exactly one projector receipt' 'select count(*) from payment_projector_receipts' '1'
assert_query 'the read row describes the projected payment' \
    "select count(*) from payment_details
      where payment_id = '${payment_id}' and source_event_id = '${event_id}'
        and merchant_id = ${merchant_id} and amount_minor = 1250 and status = 'initiated'" '1'
assert_query 'a converged delivery consumes no poison budget' \
    "select projection_failure_count from payment_outbox where event_id = '${event_id}'" '0'

# --------------------------------------------------------------------------
# 5. Duplicate delivery
# --------------------------------------------------------------------------

step 'forcing a duplicate delivery through the real pipeline'

# THE COMPLETION MARKER IS CLEARED, WHICH IS WHAT A CRASH BETWEEN COMMIT AND THE
# RELAY'S NEXT READ LEAVES BEHIND. The row re-enters the relay's partial index,
# is republished, and the consumer meets its own receipt — the exact duplicate
# at-least-once delivery produces.
psql_disposable "
    update payment_outbox
       set projection_completed_at = null, available_at = now()
     where event_id = '${event_id}'" >/dev/null

await_query 'the relay to republish the envelope' \
    "select publish_count from payment_outbox where event_id = '${event_id}'" '2' 40

await_query 'the duplicate to repair the completion marker' \
    "select count(*) from payment_outbox
      where event_id = '${event_id}' and projection_completed_at is not null" '1' 60

# CONVERGENCE, NOT DUPLICATION. One receipt and one read row after two deliveries
# is the whole idempotency claim, read from the database rather than from a log.
assert_query 'a duplicate delivery must not create a second read row' \
    'select count(*) from payment_details' '1'
assert_query 'a duplicate delivery must not create a second receipt' \
    'select count(*) from payment_projector_receipts' '1'
assert_query 'a duplicate delivery is not a failure' \
    "select projection_failure_count from payment_outbox where event_id = '${event_id}'" '0'
assert_query 'a duplicate delivery must not exhaust the envelope' \
    "select coalesce(exhausted_at::text, 'null') from payment_outbox where event_id = '${event_id}'" \
    'null'

# --------------------------------------------------------------------------
# 6. Stop reaches both processes
# --------------------------------------------------------------------------

step 'asserting the paired stop reaches both workers'
dc 'stopping the projection pair' stop scheduler horizon >/dev/null

running=$(dc 'listing running services' ps --services --filter status=running | tr -d '[:space:]' | sort | tr '\n' ' ')

grep -q 'scheduler' <<<"$running" && fail 'the scheduler survived the paired stop'
grep -q 'horizon' <<<"$running" && fail 'Horizon survived the paired stop'

elapsed=$(($(date +%s) - started_at))

printf '\n==> projection contract passed in %ss (work deadline %ss; teardown bounded separately)\n' \
    "$elapsed" "$DEADLINE_SECONDS"
