#!/usr/bin/env bash
#
# The scheduler + Horizon pair, proved end to end against real PostgreSQL and
# real Redis, in a disposable Compose project. Part of `make ci`.
#
# It reuses the development image rather than building one, so a run starts the
# bytes `make install` already built.
#
# The deadline, bounded runners and scoped teardown come from lib/contract.sh,
# shared with resilience-contract.sh.

set -euo pipefail

readonly CONTRACT_NAME='projection contract'
readonly PROJECT=ledgerflow-projection-contract
readonly DEADLINE_CEILING=180
requested_deadline=${PROJECTION_CONTRACT_DEADLINE_SECONDS:-}

source "$(dirname "${BASH_SOURCE[0]}")/lib/contract.sh"

# --------------------------------------------------------------------------
# Diagnostics
# --------------------------------------------------------------------------

# Called by lib/contract.sh cleanup() on a failing exit. Uses run_bounded
# directly, never the guarded helpers: guard() calls fail(), which would exit
# from inside cleanup and skip the teardown these diagnostics precede.
diagnose() {
    printf '\n===== projection-contract diagnostics =====\n' >&2
    printf '\n--- container state ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" ps --all >&2 2>&1 || true
    printf '\n--- scheduler (last 40) ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" logs --tail 40 scheduler >&2 2>&1 || true
    printf '\n--- horizon (last 40) ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" logs --tail 40 horizon >&2 2>&1 || true
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

# `make -n` prints the banner echoes too, and those name both services. Match the
# real Compose invocation instead, so only a genuine paired startup passes.
grep -Eq 'compose .* up .*(scheduler +horizon|horizon +scheduler)' <<<"$projection_start_plan" \
    || fail 'make projection-start does not bring the scheduler and Horizon up in one command'

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
