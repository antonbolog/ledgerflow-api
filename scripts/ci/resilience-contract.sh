#!/usr/bin/env bash
#
# Payments resilience contract, process half: the critical POST -> GET path, a
# real Redis outage, two identical POSTs held in overlapping write transactions,
# and SIGKILL of the real relay, consumer and rebuild processes,
# barrier-coordinated against real PostgreSQL and Redis in a disposable Compose
# project. Part of `make ci`.
#
# Acceptance criteria, claim boundaries against the in-process suites, and the
# barrier lock rationale:
# docs/testing/payments-resilience.md#process-level-resilience
#
# The deadline, bounded runners and scoped teardown come from lib/contract.sh,
# shared with projection-contract.sh.

set -euo pipefail

readonly CONTRACT_NAME='resilience contract'
readonly PROJECT=ledgerflow-resilience-contract
readonly DEADLINE_CEILING=420
requested_deadline=${RESILIENCE_CONTRACT_DEADLINE_SECONDS:-}

# Fixed names so `docker kill` and the cleanup trap can find these `compose run`
# containers.
readonly CRASH_CONTAINERS=(t12_relay t12_consumer t12_sigterm t12_rebuild)

source "$(dirname "${BASH_SOURCE[0]}")/lib/contract.sh"

# curl reaches nginx by service name on the project network, so no published host
# port is needed; APP_PORT=0 still avoids colliding with the developer's stack on 8080.
export APP_BIND=127.0.0.1
export APP_PORT=0

# Widened for the relay-crash step only, so SIGKILL lands in the
# after-push/before-commit window rather than racing the 500ms default timeout.
# Production reads this dial from config/payments.php and branches on nothing.
readonly RELAY_CRASH_STATEMENT_TIMEOUT_MS=20000

# Holders of the projection gate's EXCLUSIVE advisory lock. ProjectionGate takes
# the two-integer form precisely so pg_locks reports the key as classid/objid with
# objsubid = 2 and evidence can assert it exactly; the mode filter keeps a traffic
# path's transaction-scoped SHARED acquisition out of the count.
readonly GATE_EXCLUSIVE_HOLDERS="select count(*) from pg_locks
     where locktype = 'advisory' and classid = 7011 and objid = 1
       and objsubid = 2 and mode = 'ExclusiveLock' and granted"

# Set by http_request().
HTTP_CODE=''
HTTP_BODY=''
HTTP_LOCATION=''
HTTP_RETRY_AFTER=''
# Host-side client pids of the most recent spawn_process() / spawn_post() /
# start_barrier(), and the PostgreSQL backend pid await_backend_lock_wait() saw.
SPAWN_PID=''
BARRIER_PID=''
LOCK_WAIT_PID=''

# --------------------------------------------------------------------------
# Diagnostics and helpers
# --------------------------------------------------------------------------

# Called by lib/contract.sh cleanup() on a failing exit. Uses run_bounded
# directly, never the guarded helpers: guard() calls fail(), which would exit
# from inside cleanup and skip the teardown these diagnostics precede.
diagnose() {
    printf '\n===== resilience-contract diagnostics =====\n' >&2

    printf '\n--- container state ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" ps --all >&2 2>&1 || true

    for service in web app scheduler horizon; do
        printf '\n--- %s (last 40) ---\n' "$service" >&2
        run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" logs --tail 40 "$service" >&2 2>&1 || true
    done

    for name in "${CRASH_CONTAINERS[@]}"; do
        if [ -f "${workdir}/${name}.log" ]; then
            printf '\n--- %s process output ---\n' "$name" >&2
            tail -n 40 "${workdir}/${name}.log" >&2 2>&1 || true
        fi
    done

    printf '\n--- payment_outbox ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -c \
        "select event_id, publish_count, projection_completed_at, projection_failure_count,
                projection_failure_generation, last_projection_error, exhausted_at, available_at
           from payment_outbox" >&2 2>&1 || true
    printf '\n--- read model / receipts / failed_jobs / controls ---\n' >&2
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -c \
        "select (select count(*) from payment_details) as details,
                (select count(*) from payment_projector_receipts) as receipts,
                (select count(*) from failed_jobs) as failed_jobs" >&2 2>&1 || true
    run_bounded "$DIAGNOSTIC_BUDGET_SECONDS" "${DC[@]}" exec -T postgres psql -U ledgerflow -d ledgerflow -X -q -c \
        "select projection, state, owner_pid, cleared_at from payment_projection_controls" >&2 2>&1 || true

    printf '\n===========================================\n' >&2
}

redis_disposable() {
    guard "redis-cli $*" "${DC[@]}" exec -T redis redis-cli "$@"
}

# One-shot worker: steps past the critical path avoid the live Horizon supervisor
# so a running consumer never races a barrier and a crash step only kills what it spawned.
drain_consumer() {
    guard 'draining the projection queue' "${DC[@]}" run --rm -T --no-deps app \
        php artisan queue:work payments-projection --queue=payments-projection \
        --stop-when-empty --no-interaction >/dev/null 2>&1 || true
}

# Known-clean baseline before each crash step, so the crash envelope is the only
# work in flight.
drain_all() {
    artisan_disposable payments:outbox:relay >/dev/null 2>&1 || true
    drain_consumer
    await_query 'every envelope to be projected' \
        'select count(*) from payment_outbox where projection_completed_at is null' '0' 60
}

# --------------------------------------------------------------------------
# HTTP against the real endpoints, from inside the app container
# --------------------------------------------------------------------------
#
# curl runs in the `app` container and reaches nginx by service name, so the
# request travels the same PHP-FPM path a real client's would. `-i` keeps
# `Location` and `Retry-After`, both part of the query contract.

http_request() {
    local method="$1" path="$2" key="${3:-}"
    local raw

    local -a curl_args=(
        curl -sS -m 15 -i --http1.1 -H 'Expect:'
        -H "Authorization: Bearer ${TOKEN}"
        -H 'Accept: application/json'
        -X "$method" "http://web${path}"
    )

    if [ "$method" = 'POST' ]; then
        curl_args+=(
            -H 'Content-Type: application/json'
            -H "Idempotency-Key: ${key}"
            --data '{"amount_minor":1250,"currency":"USD"}'
        )
    fi

    raw=$(guard "${method} ${path}" "${DC[@]}" exec -T app "${curl_args[@]}") \
        || fail "${method} ${path}: the request itself failed to complete"

    raw=$(printf '%s' "$raw" | tr -d '\r')

    HTTP_CODE=$(printf '%s\n' "$raw" | awk '/^HTTP\//{code=$2} END{print code}')
    HTTP_LOCATION=$(printf '%s\n' "$raw" | awk -F': ' 'tolower($1)=="location"{v=$2} END{print v}')
    HTTP_RETRY_AFTER=$(printf '%s\n' "$raw" | awk -F': ' 'tolower($1)=="retry-after"{v=$2} END{print v}')
    HTTP_BODY=$(printf '%s' "$raw" | sed '1,/^$/d')
}

# Location path's last segment, falling back to the body's payment_id.
payment_id_from_response() {
    local id="${HTTP_LOCATION##*/}"

    if [ -z "$id" ]; then
        id=$(printf '%s' "$HTTP_BODY" | sed -n 's/.*"payment_id"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')
    fi

    [ -n "$id" ] || fail 'could not read a payment id from the POST response'

    printf '%s' "$id"
}

body_contains() {
    case "$HTTP_BODY" in
        *"$1"*) return 0 ;;
        *) return 1 ;;
    esac
}

# Unguarded and given a generous -m: this request is meant to sit blocked inside
# its transaction until a barrier releases it.
spawn_post() {
    local slot="$1" key="$2"

    rm -f "${workdir}/post_${slot}.log"

    "${DC[@]}" exec -T app curl -sS -m 90 -i --http1.1 -H 'Expect:' \
        -H "Authorization: Bearer ${TOKEN}" -H 'Accept: application/json' \
        -H 'Content-Type: application/json' -H "Idempotency-Key: ${key}" \
        --data '{"amount_minor":1250,"currency":"USD"}' \
        -X POST http://web/api/payments >"${workdir}/post_${slot}.log" 2>&1 &
    SPAWN_PID=$!
}

# Bounded like reap_process: a released request that never returns is the defect.
reap_post() {
    local slot="$1" client_pid="$2" timeout="${3:-60}"
    local deadline
    deadline=$(( $(date +%s) + timeout ))

    while kill -0 "$client_pid" 2>/dev/null; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            fail "the ${slot} POST did not return within ${timeout}s of the barrier release"
        fi

        check_deadline "reaping the ${slot} POST"
        sleep 0.2
    done

    # The caller asserts on the saved response; a client that died leaves none.
    wait "$client_pid" 2>/dev/null || true
}

spawned_post_code() {
    awk '/^HTTP\//{c=$2} END{print c}' "${workdir}/post_${1}.log" | tr -d '\r'
}

# --------------------------------------------------------------------------
# Payment-flow shorthands
# --------------------------------------------------------------------------
#
# Called in command substitution, so the caller's HTTP_* globals stay untouched.
# A step that asserts on the response itself calls http_request directly.

post_payment() {
    http_request POST /api/payments "$1"

    case "$HTTP_CODE" in
        202|201|200) : ;;
        *) fail "POST /api/payments (${1}) returned ${HTTP_CODE}: ${HTTP_BODY}" ;;
    esac

    payment_id_from_response
}

event_id_for() {
    query_scalar "select event_id from payment_outbox where aggregate_uuid = '$1'"
}

await_projected() {
    await_query "$1" \
        "select count(*) from payment_outbox
          where event_id = '$2' and projection_completed_at is not null" '1' "${3:-40}"
}

# A crash, a lock wait or a transport outage must cost a redelivery, never a
# charge against the envelope's five-delivery poison budget.
assert_budget_untouched() {
    local description="$1" event_id="$2"

    assert_query "${description} consumed no poison budget" \
        "select projection_failure_count from payment_outbox where event_id = '${event_id}'" '0'
    assert_query "${description} never exhausted the envelope" \
        "select coalesce(exhausted_at::text, 'null') from payment_outbox where event_id = '${event_id}'" \
        'null'
}

assert_converged_once() {
    assert_query 'it produced exactly one read row' \
        "select count(*) from payment_details where source_event_id = '$1'" '1'
    assert_query 'and exactly one projector receipt' \
        "select count(*) from payment_projector_receipts where event_id = '$1'" '1'
}

# --------------------------------------------------------------------------
# Real OS-process crash primitives
# --------------------------------------------------------------------------
#
# spawn_process backgrounds a `compose run` container as a direct child so `wait`
# can reap it. kill_process signals the container's PID 1 (`php artisan`), not
# the client, so SIGKILL is a real process kill and SIGTERM reaches the worker's
# own signal handler rather than the compose client's.

spawn_process() {
    local name="$1"
    shift

    rm -f "${workdir}/${name}.log"
    docker rm -f "$name" >/dev/null 2>&1 || true

    "${DC[@]}" run --rm -T --name "$name" "$@" >"${workdir}/${name}.log" 2>&1 &
    SPAWN_PID=$!
}

kill_process() {
    local name="$1" signal="${2:-KILL}"

    # Unwrapped by guard(): this call races a 2s statement timeout and cannot
    # afford the bounded-runner's poll tail.
    docker kill "--signal=${signal}" "$name" >/dev/null 2>&1 || true
}

# Bounded, because a SIGTERM'd worker is expected to exit ON ITS OWN: a bare
# `wait` would hang past the work deadline if graceful shutdown never completed,
# and that hang is the very defect this step exists to catch.
reap_process() {
    local name="$1" client_pid="$2" timeout="${3:-30}"
    local deadline
    deadline=$(( $(date +%s) + timeout ))

    while kill -0 "$client_pid" 2>/dev/null; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            fail "${name} did not exit within ${timeout}s of its signal"
        fi

        check_deadline "reaping ${name}"
        sleep 0.2
    done

    wait "$client_pid" 2>/dev/null || true
    guard "removing ${name}" docker rm -f "$name" >/dev/null 2>&1 || true
}

# One transaction that takes a lock and holds it inside pg_sleep until killed.
# Tagged with application_name so release_barrier can terminate the backend
# deterministically instead of relying on the client's death propagating.
start_barrier() {
    local sql="$1"

    "${DC[@]}" exec -T -e PGAPPNAME=t12_barrier postgres \
        psql -U ledgerflow -d ledgerflow -X -q -v ON_ERROR_STOP=1 \
        -c "begin; ${sql}; select pg_sleep(90);" >"${workdir}/barrier.log" 2>&1 &
    BARRIER_PID=$!
}

release_barrier() {
    kill "$BARRIER_PID" 2>/dev/null || true
    wait "$BARRIER_PID" 2>/dev/null || true

    psql_disposable "select pg_terminate_backend(pid) from pg_stat_activity
        where application_name = 't12_barrier' and pid <> pg_backend_pid()" >/dev/null 2>&1 || true
}

# Block until PostgreSQL reports a backend waiting on a lock in a statement that
# touches $1: the spawned worker has reached its write and the barrier holds a
# conflicting lock. This observation, not a sleep, gates the kill.
# Publishes the waiting backend's pid in LOCK_WAIT_PID.
await_backend_lock_wait() {
    local needle="$1" description="$2" timeout="${3:-20}"
    local deadline waiting=''
    deadline=$(( $(date +%s) + timeout ))

    while [ "$(date +%s)" -lt "$deadline" ]; do
        waiting=$(psql_disposable "
            select pid from pg_stat_activity
             where datname = 'ledgerflow'
               and wait_event_type = 'Lock'
               and state = 'active'
               and query ilike '%${needle}%'
               and coalesce(application_name, '') <> 't12_barrier'
               and pid <> pg_backend_pid()
             order by pid limit 1" 2>/dev/null | tr -d '[:space:]' || true)

        if [ -n "$waiting" ]; then
            LOCK_WAIT_PID="$waiting"

            return 0
        fi

        check_deadline "waiting for ${description}"
        sleep 0.25
    done

    fail "timed out after ${timeout}s waiting for ${description}"
}

# Block until a backend whose statement touches $1 names $2 among its blockers.
# pg_blocking_pids() resolves transaction-id, tuple and speculative-token waits,
# so a sequential replay - with no uncommitted peer - can never satisfy this.
await_blocked_by() {
    local needle="$1" blocker_pid="$2" description="$3" timeout="${4:-20}"
    local deadline blocked=''
    deadline=$(( $(date +%s) + timeout ))

    while [ "$(date +%s)" -lt "$deadline" ]; do
        blocked=$(psql_disposable "
            select count(*) from pg_stat_activity
             where datname = 'ledgerflow'
               and wait_event_type = 'Lock'
               and state = 'active'
               and query ilike '%${needle}%'
               and pid <> pg_backend_pid()
               and ${blocker_pid} = any(pg_blocking_pids(pid))" 2>/dev/null | tr -d '[:space:]' || true)

        if [ "${blocked:-0}" -ge 1 ]; then
            return 0
        fi

        check_deadline "waiting for ${description}"
        sleep 0.25
    done

    fail "timed out after ${timeout}s waiting for ${description}"
}

# --------------------------------------------------------------------------
# 0. A clean slate
# --------------------------------------------------------------------------

step 'removing any leftover disposable project and crash containers'
for name in "${CRASH_CONTAINERS[@]}"; do
    docker rm -f "$name" >/dev/null 2>&1 || true
done
run_bounded "$CLEANUP_BUDGET_SECONDS" \
    "${DC[@]}" down --volumes --remove-orphans --timeout 20 >/dev/null 2>&1 || true

# Proves the work deadline actually terminates a hang. Runs before anything is
# created:
#   RESILIENCE_CONTRACT_DEADLINE_SECONDS=15 RESILIENCE_CONTRACT_SELF_TEST=1 \
#     ./scripts/ci/resilience-contract.sh
if [ "${RESILIENCE_CONTRACT_SELF_TEST:-0}" = '1' ]; then
    step 'SELF-TEST: hanging deliberately, to prove the deadline terminates it'
    guard 'the deliberate self-test hang' sleep 600
    fail 'the self-test hang was not terminated by the deadline'
fi

step 'asserting the development image is already built'
guard 'inspecting the development image' \
    docker image inspect "${LEDGERFLOW_APP_IMAGE:-ledgerflow-app:latest}" >/dev/null 2>&1 \
    || fail 'the development image does not exist; run `make install` first'

step 'asserting make ci runs this suite'
grep -q 'resilience-contract' <<<"$(make -n ci 2>&1)" \
    || fail 'make ci does not run resilience-contract'

# --------------------------------------------------------------------------
# 1. Infrastructure, schema, a token
# --------------------------------------------------------------------------

step 'starting isolated PostgreSQL, Redis, nginx and PHP-FPM'
dc 'starting postgres and redis' up -d --wait --wait-timeout 60 postgres redis >/dev/null
dc 'starting app and web' up -d --wait --wait-timeout 90 app web >/dev/null

step 'migrating and seeding the disposable database'
artisan_disposable migrate --force >/dev/null
artisan_disposable db:seed --force >/dev/null

merchant_id=$(query_scalar "select id from merchants where slug = 'northwind-trading'")
[ -n "$merchant_id" ] || fail 'the disposable database has no seeded merchant'

step 'minting a real Sanctum bearer token for the seeded merchant user'
TOKEN=$(artisan_disposable tinker --execute \
    "echo \Modules\Merchant\Infrastructure\Persistence\Eloquent\User::query()->where('email','owner@northwind.test')->firstOrFail()->createToken('t12-resilience')->plainTextToken;" \
    2>/dev/null | tr -d '\r' | grep -oE '[0-9]+\|[A-Za-z0-9]{40,}' | tail -1)
[ -n "$TOKEN" ] || fail 'could not mint a Sanctum token'
readonly TOKEN

# --------------------------------------------------------------------------
# 2. The critical path: POST -> outbox -> Redis -> consumer -> GET
# --------------------------------------------------------------------------

step 'starting the Horizon consumer'
dc 'starting horizon' up -d horizon >/dev/null
await 'Horizon to report itself running' 40 \
    "${DC[@]}" exec -T horizon php artisan horizon:status

step 'POST /api/payments creates exactly one durable payment'
critical_payment_id=$(post_payment 't12-critical-path')

assert_query 'one idempotency receipt' 'select count(*) from payment_idempotency_receipts' '1'
assert_query 'one stored event' 'select count(*) from stored_events' '1'
assert_query 'one outbox envelope' 'select count(*) from payment_outbox' '1'

step 'GET is pending before the pipeline runs, then projected after it'
http_request GET "/api/payments/${critical_payment_id}"
[ "$HTTP_CODE" = '202' ] \
    || fail "GET before the relay returned ${HTTP_CODE}, expected 202: ${HTTP_BODY}"
body_contains '"status":"projection_pending"' \
    || fail "202 body is not projection_pending: ${HTTP_BODY}"
[ "$HTTP_RETRY_AFTER" = '1' ] || fail "202 is missing Retry-After: 1 (saw '${HTTP_RETRY_AFTER}')"

artisan_disposable payments:outbox:relay >/dev/null
await_query 'the projection to complete' \
    "select count(*) from payment_outbox where projection_completed_at is not null" '1' 40

http_request GET "/api/payments/${critical_payment_id}"
[ "$HTTP_CODE" = '200' ] || fail "GET after projection returned ${HTTP_CODE}: ${HTTP_BODY}"
body_contains "\"payment_id\":\"${critical_payment_id}\"" || fail "200 body wrong id: ${HTTP_BODY}"
body_contains '"amount_minor":1250' || fail "200 body wrong amount: ${HTTP_BODY}"
body_contains '"currency":"USD"' || fail "200 body wrong currency: ${HTTP_BODY}"
body_contains '"status":"initiated"' || fail "200 body wrong status: ${HTTP_BODY}"
body_contains '"initiated_at"' || fail "200 body missing initiated_at: ${HTTP_BODY}"

assert_query 'one read row' 'select count(*) from payment_details' '1'
assert_query 'one projector receipt' 'select count(*) from payment_projector_receipts' '1'
assert_query 'the critical path consumed no poison budget' \
    'select coalesce(sum(projection_failure_count), 0) from payment_outbox' '0'

step 'stopping the live consumer; the remaining steps drive deliberate one-shot workers'
dc 'stopping horizon' stop --timeout 25 horizon >/dev/null

# --------------------------------------------------------------------------
# 3. Redis outage and recovery: pending -> projected
# --------------------------------------------------------------------------

step 'stopping Redis to take the transport offline'
dc 'stopping redis' stop redis >/dev/null

step 'a POST during the outage commits, and GET reports it pending'
outage_payment_id=$(post_payment 't12-redis-outage')

# The write path never touches Redis, so the envelope is durable; the relay
# cannot publish it while Redis is down.
artisan_disposable payments:outbox:relay >/dev/null 2>&1 || true
outage_event_id=$(event_id_for "$outage_payment_id")

assert_query 'the outage envelope was never published' \
    "select publish_count from payment_outbox where event_id = '${outage_event_id}'" '0'
assert_budget_untouched 'a transport outage' "$outage_event_id"

http_request GET "/api/payments/${outage_payment_id}"
[ "$HTTP_CODE" = '202' ] || fail "GET during the outage returned ${HTTP_CODE}: ${HTTP_BODY}"
body_contains '"status":"projection_pending"' || fail "outage GET not pending: ${HTTP_BODY}"

step 'restoring Redis and watching the backlog converge'
dc 'starting redis' start redis >/dev/null
await 'Redis to answer again' 30 "${DC[@]}" exec -T redis redis-cli ping
artisan_disposable payments:outbox:relay >/dev/null
drain_consumer

await_projected 'the outage payment to project after recovery' "$outage_event_id"

http_request GET "/api/payments/${outage_payment_id}"
[ "$HTTP_CODE" = '200' ] || fail "GET after Redis recovery returned ${HTTP_CODE}: ${HTTP_BODY}"
assert_budget_untouched 'recovery from a transport outage' "$outage_event_id"

# --------------------------------------------------------------------------
# 4. Concurrent identical POST -> one logical payment
# --------------------------------------------------------------------------
#
# Barrier: LOCK TABLE stored_events IN SHARE MODE. Compatible with the ACCESS
# SHARE of the aggregate's replay read, conflicting with the ROW EXCLUSIVE its
# insert needs, so the first POST blocks while holding its uncommitted
# idempotency reservation. Both waits are observed before the release.

step 'draining the pipeline, then holding a SHARE lock on stored_events'
drain_all
before_events=$(query_scalar "select count(*) from stored_events")

race_key='t12-concurrent-identical'
start_barrier 'lock table stored_events in share mode'

# The first POST must find the lock already held, or it would sail past.
await_query 'the barrier to hold the stored_events lock' \
    "select count(*) from pg_locks l
       join pg_stat_activity a using (pid)
      where a.application_name = 't12_barrier'
        and l.locktype = 'relation'
        and l.relation = 'stored_events'::regclass
        and l.mode = 'ShareLock'
        and l.granted" '1' 20

step 'the first POST reserves its idempotency key, then blocks before committing'
spawn_post a "$race_key"
first_client=$SPAWN_PID
await_backend_lock_wait 'stored_events' \
    'the first POST to block on its stored_events insert' 30
first_backend="$LOCK_WAIT_PID"

step 'the second identical POST blocks on that uncommitted reservation'
spawn_post b "$race_key"
second_client=$SPAWN_PID
await_blocked_by 'payment_idempotency_receipts' "$first_backend" \
    "the second POST to be blocked by backend ${first_backend}, the first POST's open transaction" 30

step 'releasing the barrier; both overlapping requests must answer alike'
release_barrier
reap_post a "$first_client"
reap_post b "$second_client"

for slot in a b; do
    code=$(spawned_post_code "$slot")
    case "$code" in
        202|201|200) : ;;
        *) fail "concurrent POST slot ${slot} returned ${code}" ;;
    esac
done

race_ids=$(grep -hoE '"payment_id":"[0-9a-f-]{36}"' "${workdir}"/post_a.log "${workdir}"/post_b.log \
    | sed 's/.*:"//;s/"//' | sort -u)
distinct_ids=$(printf '%s\n' "$race_ids" | grep -c .)
[ "$distinct_ids" = '1' ] || fail "concurrent identical POSTs produced ${distinct_ids} distinct payment ids"

after_events=$(query_scalar "select count(*) from stored_events")
[ "$((after_events - before_events))" = '1' ] \
    || fail "concurrent identical POSTs created $((after_events - before_events)) stored events, expected 1"

race_aggregate="$race_ids"
assert_query 'concurrent identical POSTs created one idempotency receipt' \
    "select count(*) from payment_idempotency_receipts where payment_id = '${race_aggregate}'" '1'
assert_query 'concurrent identical POSTs created one outbox envelope' \
    "select count(*) from payment_outbox where aggregate_uuid = '${race_aggregate}'" '1'

# --------------------------------------------------------------------------
# 5. Relay SIGKILL after a real Redis push, before the commit
# --------------------------------------------------------------------------
#
# Barrier: LOCK TABLE payment_outbox IN SHARE MODE. Compatible with the relay's
# FOR UPDATE SKIP LOCKED select but conflicts with its post-push acceptance
# UPDATE, so the relay pushes to Redis then blocks exactly before commit. A row
# lock would instead make it SKIP the envelope. See the spec for the full rationale.

step 'draining the pipeline, then creating a dedicated envelope for the relay crash'
drain_all
relay_crash_payment_id=$(post_payment 't12-relay-crash')
relay_crash_event_id=$(event_id_for "$relay_crash_payment_id")

step 'holding a SHARE lock so the relay blocks after its push'
start_barrier 'lock table payment_outbox in share mode'

step 'spawning the real relay process and killing it after the push'
spawn_process t12_relay --no-deps \
    -e "PAYMENTS_RELAY_STATEMENT_TIMEOUT_MS=${RELAY_CRASH_STATEMENT_TIMEOUT_MS}" \
    -e 'PAYMENTS_RELAY_START_BUDGET_MS=20000' \
    app php artisan payments:outbox:relay
relay_client=$SPAWN_PID

await_backend_lock_wait 'payment_outbox' 'the relay to block on its acceptance UPDATE' 30

kill_process t12_relay KILL
reap_process t12_relay "$relay_client"
release_barrier

step 'the killed relay left the envelope eligible and consumed no budget'
assert_query 'the crash rolled the envelope back to unpublished' \
    "select publish_count from payment_outbox where event_id = '${relay_crash_event_id}'" '0'
assert_budget_untouched 'a relay crash' "$relay_crash_event_id"

step 'a later relay pass republishes and the projection converges'
artisan_disposable payments:outbox:relay >/dev/null
drain_consumer
await_projected 'the crashed-relay payment to project' "$relay_crash_event_id"
assert_converged_once "$relay_crash_event_id"

# --------------------------------------------------------------------------
# 6. Consumer SIGKILL mid projection transaction
# --------------------------------------------------------------------------
#
# Barrier: a bare FOR UPDATE row lock. The consumer selects its envelope the same
# way (no SKIP LOCKED), so it blocks at the start of its write phase, after the
# gate check and before any receipt, read row or marker. The kill is gated on
# that observed row-lock wait.

step 'draining the pipeline, then publishing one envelope for the consumer crash'
drain_all
consumer_payment_id=$(post_payment 't12-consumer-crash')
consumer_event_id=$(event_id_for "$consumer_payment_id")

artisan_disposable payments:outbox:relay >/dev/null
await_query 'the envelope to be published' \
    "select publish_count from payment_outbox where event_id = '${consumer_event_id}'" '1' 20

step 'holding the envelope row lock so the consumer blocks on it'
start_barrier "select 1 from payment_outbox where event_id = '${consumer_event_id}' for update"

step 'spawning a real queue worker and killing it inside its transaction'
spawn_process t12_consumer --no-deps app \
    php artisan queue:work payments-projection --queue=payments-projection --once --no-interaction
consumer_client=$SPAWN_PID

await_backend_lock_wait 'payment_outbox' 'the consumer to block on the envelope row lock' 30

kill_process t12_consumer KILL
reap_process t12_consumer "$consumer_client"
release_barrier

step 'the killed consumer wrote nothing and consumed no poison budget'
assert_query 'no receipt was written by the killed consumer' \
    "select count(*) from payment_projector_receipts where event_id = '${consumer_event_id}'" '0'
assert_query 'no read row was written by the killed consumer' \
    "select count(*) from payment_details where source_event_id = '${consumer_event_id}'" '0'
assert_query 'the completion marker is still unset' \
    "select coalesce(projection_completed_at::text, 'null') from payment_outbox where event_id = '${consumer_event_id}'" \
    'null'
assert_budget_untouched 'a worker crash' "$consumer_event_id"

step 'a fresh worker picks up the redelivery and it converges'
# Redelivery comes from the relay's reconciliation grace, not the reserved Redis
# message (held 90s). Backdate available_at so that grace has already elapsed.
psql_disposable "update payment_outbox
       set available_at = now() - interval '1 second'
     where event_id = '${consumer_event_id}'" >/dev/null
artisan_disposable payments:outbox:relay >/dev/null
drain_consumer
await_projected 'the crashed-consumer payment to project' "$consumer_event_id" 60
assert_converged_once "$consumer_event_id"

# --------------------------------------------------------------------------
# 7. Consumer SIGTERM mid projection transaction: the job finishes
# --------------------------------------------------------------------------
#
# The other half of the signal contract, and the one a deploy actually sends.
# Laravel's worker installs a SIGTERM handler (pcntl_async_signals) that sets
# shouldQuit rather than dying, so the in-flight projection must COMMIT and the
# process must then exit on its own - the opposite outcome to section 6 from the
# same barrier position. The worker runs without --once, so exiting is evidence
# that the signal was honoured rather than the command simply ending.

step 'draining the pipeline, then publishing one envelope for the consumer SIGTERM'
drain_all
term_payment_id=$(post_payment 't12-consumer-sigterm')
term_event_id=$(event_id_for "$term_payment_id")

artisan_disposable payments:outbox:relay >/dev/null
await_query 'the SIGTERM envelope to be published' \
    "select publish_count from payment_outbox where event_id = '${term_event_id}'" '1' 20

step 'holding the envelope row lock so the worker blocks inside its transaction'
start_barrier "select 1 from payment_outbox where event_id = '${term_event_id}' for update"

step 'spawning a real queue worker and asking it to stop while it is mid transaction'
spawn_process t12_sigterm --no-deps app \
    php artisan queue:work payments-projection --queue=payments-projection --no-interaction
term_client=$SPAWN_PID

await_backend_lock_wait 'payment_outbox' 'the worker to block on the envelope row lock' 30

kill_process t12_sigterm TERM
# Released BEFORE the reap, unlike a SIGKILL step: the worker cannot finish the
# job it was asked to finish while the barrier still holds its row.
release_barrier
reap_process t12_sigterm "$term_client" 45

step 'the SIGTERM worker committed its projection and exited by itself'
assert_query 'the completion marker was written' \
    "select count(*) from payment_outbox
      where event_id = '${term_event_id}' and projection_completed_at is not null" '1'
assert_converged_once "$term_event_id"
assert_budget_untouched 'a graceful shutdown' "$term_event_id"

http_request GET "/api/payments/${term_payment_id}"
[ "$HTTP_CODE" = '200' ] || fail "GET after a graceful worker shutdown returned ${HTTP_CODE}: ${HTTP_BODY}"

# --------------------------------------------------------------------------
# 8. Rebuild SIGKILL while it holds the rebuilding state, then guarded recovery
# --------------------------------------------------------------------------
#
# Barrier: SHARE lock on payment_details. The rebuild commits the `rebuilding`
# state in its own transaction, then blocks on the destructive clear (DELETE,
# ROW EXCLUSIVE), so `rebuilding` is durably observable and the kill is gated on
# that lock wait. Whether a clear committed decides which recovery applies, so
# the loop below runs authorized rebuilds until verification passes.
# PAYMENTS_REBUILD_BATCH_SIZE=1 is a documented operational dial.

step 'draining, then corrupting the read model so verification fails'
drain_all
projected_total=$(query_scalar 'select count(*) from payment_details')
[ "${projected_total:-0}" -ge 3 ] \
    || fail "expected at least 3 projected payments for the rebuild step, saw ${projected_total}"
psql_disposable "delete from payment_details
    where payment_id = (select payment_id from payment_details order by initiated_at limit 1)" >/dev/null

set +e
artisan_disposable payments:projections:verify >/dev/null 2>&1
verify_before=$?
set -e
[ "$verify_before" -ne 0 ] || fail 'verification passed against a corrupted read model'

step 'spawning the real rebuild process and killing it while it holds rebuilding'
start_barrier 'lock table payment_details in share mode'

spawn_process t12_rebuild --no-deps \
    -e 'PAYMENTS_REBUILD_BATCH_SIZE=1' \
    app php artisan payments:projections:rebuild --confirm-rebuild
rebuild_client=$SPAWN_PID

await_query 'the rebuild to claim the rebuilding state' \
    "select state from payment_projection_controls where projection = 'payment_details'" 'rebuilding' 40
await_backend_lock_wait 'payment_details' 'the rebuild to block on the destructive clear' 30

# Non-vacuous control for the release assertion below, and the thing that pins
# the hardcoded key: if CLASS_ID or the object id ever moved, this fails here
# rather than letting the post-kill count pass against nothing.
assert_query 'the live rebuild holds the exclusive gate lock' "$GATE_EXCLUSIVE_HOLDERS" '1'

kill_process t12_rebuild KILL
reap_process t12_rebuild "$rebuild_client"
release_barrier

step 'the killed rebuild left a closed gate that defers traffic without budget'
# We killed a CONTAINER, not a backend, so the socket close is what has to reach
# PostgreSQL before it drops the session lock. Asserted here rather than left to
# the recovery loop below, whose three attempts would mask a slow release.
await_query 'the killed rebuild advisory lock to be released' "$GATE_EXCLUSIVE_HOLDERS" '0' 15

assert_query 'the rebuilding state survived the crash' \
    "select state from payment_projection_controls where projection = 'payment_details'" 'rebuilding'

http_request POST /api/payments 't12-during-rebuild'
[ "$HTTP_CODE" = '503' ] || fail "POST during a rebuild returned ${HTTP_CODE}, expected 503"
body_contains 'projection_rebuilding' || fail "503 body is not projection_rebuilding: ${HTTP_BODY}"

http_request GET "/api/payments/${critical_payment_id}"
[ "$HTTP_CODE" = '503' ] || fail "GET during a rebuild returned ${HTTP_CODE}, expected 503"

# Make one envelope eligible so "the relay declined to publish" is not vacuous.
# The recovery runs below replay every canonical event regardless.
psql_disposable "update payment_outbox
       set projection_completed_at = null, available_at = now() - interval '1 second'
     where event_id = '${consumer_event_id}'" >/dev/null

before_failcount=$(query_scalar 'select coalesce(sum(projection_failure_count),0) from payment_outbox')
before_failed_jobs=$(query_scalar 'select count(*) from failed_jobs')
before_publish=$(query_scalar "select publish_count from payment_outbox where event_id = '${consumer_event_id}'")

artisan_disposable payments:outbox:relay >/dev/null 2>&1 || true
drain_consumer

[ "$before_publish" = "$(query_scalar "select publish_count from payment_outbox where event_id = '${consumer_event_id}'")" ] \
    || fail 'the relay published an eligible row while the gate was closed'
[ "$before_failcount" = "$(query_scalar 'select coalesce(sum(projection_failure_count),0) from payment_outbox')" ] \
    || fail 'relay/consumer deferral during a rebuild changed the poison budget'
[ "$before_failed_jobs" = "$(query_scalar 'select count(*) from failed_jobs')" ] \
    || fail 'a deferred delivery during a rebuild reached failed_jobs'

step 'authorized recovery runs finish the rebuild and reopen the gate'
recovered=0
for attempt in 1 2 3; do
    recovery_json=$(artisan_disposable payments:projections:rebuild --confirm-rebuild)
    grep -q '"command":"payments:projections:rebuild"' <<<"$recovery_json" \
        || fail "recovery run ${attempt} produced no recognisable JSON: ${recovery_json}"

    await_query "the gate to reopen (attempt ${attempt})" \
        "select state from payment_projection_controls where projection = 'payment_details'" 'ready' 40

    set +e
    artisan_disposable payments:projections:verify >/dev/null 2>&1
    verify_after=$?
    set -e

    if [ "$verify_after" -eq 0 ]; then
        recovered=1
        break
    fi
done
[ "$recovered" = '1' ] || fail 'verification never passed after the recovery rebuilds'

http_request GET "/api/payments/${critical_payment_id}"
[ "$HTTP_CODE" = '200' ] || fail "GET after the recovery rebuild returned ${HTTP_CODE}: ${HTTP_BODY}"

# --------------------------------------------------------------------------
# 9. Teardown leaves no orphan
# --------------------------------------------------------------------------

step 'asserting no crash process survived'
for name in "${CRASH_CONTAINERS[@]}"; do
    if docker ps -a --format '{{.Names}}' | grep -qx "$name"; then
        fail "crash container ${name} survived the suite"
    fi
done

running=$(dc 'listing running services' ps --services --filter status=running | tr -d '[:space:]' | tr '\n' ' ')
grep -q 'scheduler' <<<"$running" && fail 'the scheduler is running; this suite never starts it'

elapsed=$(($(date +%s) - started_at))
printf '\n==> resilience contract passed in %ss (work deadline %ss; teardown bounded separately)\n' \
    "$elapsed" "$DEADLINE_SECONDS"
