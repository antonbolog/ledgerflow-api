# Runtime Contract

This document defines application processes and required operational evidence. Concrete AWS
resources and deployment topology belong to `ledgerflow-infra`.

## Application process topology

Processes reuse the `ledgerflow-api` image where practical but remain independently supervised,
scaled, restarted, and observed.

| Process | Responsibility | Failure evidence |
|---|---|---|
| HTTP/API + Filament | Serve `/api/*`, health endpoints, and `/admin` | Health, latency, error rate, Problem Details, admin audit evidence |
| Horizon workers | Supervise Redis queues such as `payments-projection` | Queue wait, throughput, failed jobs, worker health |
| Scheduler/relay | Run bounded scheduled commands and outbox relay | Heartbeat, oldest eligible outbox age, command result |
| Reverb | Terminate authenticated WebSocket connections | Connection count, disconnects, broadcast failures, Redis Pub/Sub health |
| SQS workers (future) | Consume separately owned SQS workloads | Queue age, receive/delete failures, worker health, DLQ movement |

The first Payments slice requires HTTP/API, Horizon, and scheduler/relay. Reverb and SQS workers
arrive only with their milestones.

### Local process topology

`make install` and `make start` bring up four services and no workers:

| Compose service | Process | Healthcheck |
|---|---|---|
| `web` | nginx serving `/api/*` and `/up` on `127.0.0.1:8080`, loopback only | HTTP `/up` |
| `app` | PHP-FPM, and the container operator commands run in | PHP-FPM status |
| `postgres` | PostgreSQL 18, business and delivery truth | `pg_isready` |
| `redis` | Redis 8, queue, cache and coordination | `redis-cli ping` |

`make projection-start` adds two more from the opt-in `projection` profile, together and never one
alone, and `make projection-stop` removes the same pair:

| Compose service | Process | Healthcheck |
|---|---|---|
| `scheduler` | `php artisan schedule:work`, which runs the per-second outbox relay | `payments:outbox:status` |
| `horizon` | `php artisan horizon`, supervising the `payments-projection` queue | `horizon:status` |

**A clean checkout whose outbox never moves is a missing `projection-start`, not a defect.** Payments
commit correctly with the pair down; `GET /api/payments/{paymentId}` simply answers
`202 projection_pending` indefinitely.

**A Redis outage stops the scheduler's current plan.** `schedule:work` plans sub-minute events one
minute at a time, so after Redis returns an untouched scheduler resumes at the next minute boundary
rather than instantly; `docker compose restart scheduler` makes recovery immediate. Horizon
reconnects on its own. Neither case consumes projection failure budget or exhausts an envelope.

Three `demo-` Make targets support [the five-minute demo](five-minute-demo.md): `demo-token` prints a
Sanctum token for a seeded fixture identity, `demo-status` shows relay and Horizon state read-only,
and `demo-corrupt` deletes one named `payment_details` row behind a typed confirmation and the local
database allowlist. They are local development tooling. No validation target performs their effect:
`make check` never invokes them, and `ci-contract` asserts only that their guards refuse, alongside
the `destroy` and `db-reset` guards it already covers.

**Scheduler/relay, as implemented by T7.** `Schedule::command('payments:outbox:relay')->everySecond()`
in root `routes/console.php`, run by `php artisan schedule:work`. `payments:outbox:status` is the
health surface: exit `0` only when the scheduler heartbeat is fresh (one of five states — `fresh`,
`stale`, `missing`, `unavailable`, `invalid`) **and** one snapshot-consistent PostgreSQL aggregate
succeeds; JSON carries no exception message. Local Compose runs it as the opt-in `scheduler` service
behind profile `projection`, reusing the `app` image's `dev` target with `command: php artisan
schedule:work` and a `payments:outbox:status` healthcheck; `make install`/`make start` never bring it
up, and `make stop`/`make destroy` are profile-aware so they still reach it. **The relay stays
dormant until an operator starts the scheduler and its Horizon-supervised `payments-projection`
consumer together** - see
[outbox delivery](../specifications/payments/outbox-delivery.md#scheduler-contract) and
[ADR 0004](../adr/0004-lock-through-push-outbox-relay.md).

**Implemented.** One opt-in `projection` profile carries both the `scheduler` and the `horizon`
service, and both run the same `app` image with only their `command:` differing. Activation is a
paired operator action through `make projection-start` / `make projection-stop`;
`projection-start` depends on `db-migrate`, so workers never start against a schema without
`payment_projector_receipts` and `payment_details`. `make install`/`make start` stay worker-free.

**There is deliberately no target that starts one without the other.** The relay publishes into
`payments-projection` and Horizon is the only thing that drains it, so a scheduler running alone
produces a growing backlog that reads as a consumer fault rather than as a missing process. This
supersedes both the "normal deployment" and "T8 activates" phrasings still carried by the roadmap,
outbox delivery, and ADR 0004's consequences.

The `horizon` service's healthcheck is `php artisan horizon:status`, which reads the master
supervisor record from Redis and exits `0` only while Horizon is running - `2` when no master is
registered, `1` when every master is paused - so a dead or paused supervisor fails the check while
its container is still alive. Its `stop_grace_period` is 20s, above the 15-second worker timeout, so
SIGTERM lets an in-flight projection finish rather than being SIGKILLed mid-transaction.

`make projection-contract` proves the pair end to end in a disposable Compose project with its own
PostgreSQL and Redis volumes, reusing the already-built development image and never touching the
development stack.

**Its 180-second deadline bounds the contract's WORK, not the whole wall clock.** Every external
command - not merely each step - runs under a budget of whatever is left of that deadline, so no
single hung `docker compose exec`, `psql`, or `artisan` can outlive it; the polling loops keep their
own attempt limits on top. Guaranteed scoped cleanup takes precedence over a flat wall-clock cap, so
once the work stops the obligatory teardown adds a **separately bounded tail**: at most four
10-second diagnostics plus one 60-second `down --volumes`. Total wall clock on a terminated run is
therefore the work deadline plus that bounded tail, never unbounded.

**Teardown is mandatory, so a teardown failure is a contract failure.** Every stage is attempted even
after an earlier one fails and each stays bounded; a run whose work passed but whose teardown left a
crash container, a Compose service, a volume or its fixture directory behind exits non-zero, while a
run that already failed keeps its first cause. Diagnostics are best-effort and never skip the
teardown they precede.

`PROJECTION_CONTRACT_DEADLINE_SECONDS` may shorten the work deadline but never lengthen it past 180;
a non-numeric or out-of-range value falls back to 180. `PROJECTION_CONTRACT_SELF_TEST=1` runs one
deliberate hang before anything is created, which is how the enforcement itself is evidenced without
waiting out the full budget.

The contract prints expanded diagnostics only on failure, tears the project down on every exit
including the deadline path, and runs as part of `make ci`. It proves paired startup, a backlog while the consumer is stopped that consumes no poison
budget, restart convergence, duplicate delivery converging to one receipt and one read row, and that
the paired stop reaches both processes.

`make resilience-contract` proves the process half of the Payments resilience plan in a second
disposable Compose project built on the shared `scripts/ci/lib/contract.sh` harness - bounded work
deadline, scoped teardown, failure-only diagnostics, `RESILIENCE_CONTRACT_SELF_TEST=1` hang - and
also runs as part of `make ci`.
It drives the critical `POST -> GET` HTTP path, a real Redis-daemon outage and recovery, two identical
`POST`s held in overlapping write transactions, barrier-coordinated `SIGKILL` of the real relay,
consumer and rebuild processes, and a `SIGTERM` of a real worker from the same barrier position that
must commit its projection and exit by itself. Each signal is gated on an observed PostgreSQL lock
wait or control-row state rather than a timer, and the concurrent `POST` is released only after
`pg_blocking_pids()` names the first request's backend as the second's blocker. Its claim boundaries against the integration evidence live in the
[resilience test plan](../testing/payments-resilience.md#process-level-resilience).

That consumer gets **one Horizon execution per published job**; outbox reconciliation, not Horizon,
owns republication after grace. Accepted starting values: one process, a 15-second Horizon worker
timeout, the existing 90-second queue reservation, and a new 2-second projection-local PostgreSQL
statement timeout, with `statement_timeout < worker timeout < retry_after` asserted by config tests.
Retained failed-job records are operational history; PostgreSQL outbox state remains delivery truth.

PostgreSQL stores business and delivery truth. Redis supports cache, coordination, Pub/Sub, and
internal queues; it is never the sole recovery source for financial state.

Local Docker Compose eventually runs implemented processes from the application image. Exact
commands become canonical through project Make targets when their milestones land.

## Required operational evidence

Structured logs exclude raw idempotency keys and include available payment, merchant, event,
message, and correlation identifiers. At minimum expose:

- API and admin request health without logging secrets or sensitive payment payloads;
- relay attempts, transport failures, and confirmed handler failures;
- relay transaction duration and transport publish latency;
- database connection/lock pressure attributable to relay;
- scheduler heartbeat and oldest eligible outbox age;
- count and oldest age of outbox events without the named projector receipt;
- Redis queue lag, duplicate deliveries, failed jobs, and worker restarts;
- SQS queue age, DLQ depth, redrive results, and worker failures when SQS is introduced;
- Reverb connection and broadcast health when realtime is introduced;
- rebuild state and owner from `payment_projection_controls`, plus high watermark, confirmed batch progress, and final result from rebuild JSON and correlated logs (implemented by T11).

The verifier reports corruption; it does not repair or rebuild automatically. Repair and rebuild
remain explicit developer/operator procedures with audit evidence.

**T10's two commands are implemented, and neither adds a process.**
`payments:projections:verify` is a manual read-only command that stays **unscheduled**: no recurring
schedule entry, queue, Horizon supervisor, or deployment automation is introduced with it, and a
scheduled run waits for a monitoring and alert destination to exist. `payments:outbox:repair
{eventId}` is an **offline delivery operation**: the operator stops the scheduler and Horizon together
through `make projection-stop`, confirms that every delivery and failure-recorder process has drained
(including standalone consumers, manual commands, and processes on other hosts, none of which the
command can discover), passes the explicit `--delivery-quiesced` acknowledgement, then verifies again
and resumes through `make projection-start`. The acknowledgement is the operator's assertion recorded in
the audit, not machine-verified proof.

That audit is **structured application logs correlated by one operation identifier, not a new table**.
Commit and log persistence are not one transaction, so an uncertain outcome is resolved by inspecting
state or re-running verification, never by repeating the mutation. Contracts in
[verification and rebuild](../specifications/payments/verification-and-rebuild.md).

### Manual verification and targeted repair

Use the existing running `app` container for these operator commands; do not run host PHP.
The commands use that container's configured database, so confirm the intended environment before repair.
They are operational actions, not substitutes for `make check` or `make ci`.

1. Run `docker compose exec -T app php artisan payments:projections:verify` and retain its JSON and exit status.
   Verification may run while delivery is active and does not stop workers or mutate data.
2. Interpret the verification exit code before choosing an action:
   `0` means consistent or ordinary in-flight delivery; `1` means confirmed inconsistency;
   `2` means an incomplete audit (including `rebuild_in_progress`) or invalid configuration; `3` means operational attention for overdue delivery, exhaustion, or future event time.
   An incomplete report never establishes that the remaining history is clean.
3. If a finding requires correction of one outbox envelope, run `make projection-stop`.
   Independently confirm every scheduler, consumer, manual delivery command and delayed failure recorder has finished, including other hosts.
   Stopping the local pair alone is insufficient; the acknowledgement flag does not discover workers.
4. Run `docker compose exec -T app php artisan payments:outbox:repair <event-id> --delivery-quiesced`, replacing `<event-id>` with the validated durable event UUID, not the payment ID.
   Preserve the JSON, exit status and `operation_id` for audit correlation.
5. Run a fresh verification and assess all remaining findings before resuming the pair with `make projection-start`.
   A corrected but unprojected envelope can remain overdue while workers are stopped; that alone does not mean repair failed.
   Repair does not clear exhaustion: only an explicitly chosen `payments:outbox:retry <event-id>` reactivates an exhausted envelope after its cause is corrected.
   A receipt without its read row requires the controlled rebuild procedure below.
6. When resumption is appropriate, verify again after delivery has had an opportunity to converge.
   Keep unresolved read-model corruption visible until controlled rebuild and fresh verification establish its correction.

Repair exit `0` means `created`, `repaired`, or `unchanged`, with the final log call succeeding.
Exit `1` is an expected refusal, including `rebuild_in_progress` before canonical or envelope locking; exit `2` is invalid input, runtime/timeout failure, audit failure, or commit uncertainty.
Always inspect `mutation_state`: `committed` with `audit_failed` must not be described as rollback, and `unknown` must not be treated as no mutation.
A failed final audit preserves an already known `rolled_back`, `unknown`, or `no_changes` state.
Do not automatically repeat repair after a failed or missing result; inspect durable state and obtain a fresh verification first.
Log success is not an atomic durable audit ledger, and a killed process or unavailable stdout may leave no final JSON.

The verifier has no task-specific CLI options.
`PAYMENTS_VERIFICATION_BATCH_SIZE` defaults to 100 (1-1000), `PAYMENTS_VERIFICATION_EXAMPLE_LIMIT` to 50 (0-200), and `PAYMENTS_VERIFICATION_GRACE_SECONDS` to 30 (1-86400), through `config/payments.php`.
The shipped safety limits are a 2-second statement timeout, a 120-second audit transaction and a 64-KiB payload inspection cap; repair uses 2-second statements and a 10-second transaction.
These limits are not throughput or whole-process wall-clock guarantees.
All invocation output carries safe categories and field names, never raw payment payloads or exception text.
Invalid task-specific arguments/options return exit `2` and `invalid_invocation` without scanning or mutation.

Executable evidence and the limits of the concurrency and query-plan claims live in the
[T10 evidence table](../specifications/payments/verification-and-rebuild.md#t10-implementation-evidence).

### Manual rebuild and recovery

Run rebuild only against the intended environment through its running application container.
It clears `payment_details` and the named projector's receipts and reconstructs them from canonical `stored_events`.
It reconciles missing outbox completion markers but never recreates envelopes or changes idempotency receipts, canonical events, failure history, or exhaustion.
This is an availability interruption: POST and GET return `503 projection_rebuilding`; relay and consumer defer, and verify/repair decline while the gate is closed.
Existing in-flight shared transactions must finish before the exclusive rebuild lock is acquired.
There is no queue flush and no requirement to stop the pair for rebuild itself; outbox reconciliation redelivers deferred work after the gate reopens.
Targeted envelope repair still requires the separate `--delivery-quiesced` procedure above.

1. Retain the current verification JSON and identify whether the problem belongs to read-model rebuild, envelope repair, or canonical-history investigation.
   If verification returns `rebuild_in_progress`, inspect the gate before starting another run.
2. Inspect committed control state and actual lock holders.
   For the repository's local Compose environment, these read-only operator commands show both:

   ```sh
   docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -c 'SELECT projection, state, owner_token, owner_pid, started_at, cleared_at FROM payment_projection_controls;'
   docker compose exec -T postgres psql -U ledgerflow -d ledgerflow -c "SELECT pid, mode, granted FROM pg_locks WHERE locktype = 'advisory' AND database = (SELECT oid FROM pg_database WHERE datname = current_database()) AND classid = 7011 AND objid = 1 AND objsubid = 2;"
   ```

   Use the corresponding approved database connection outside this local environment.
   The recorded PID is diagnostic; it does not prove that its process or session still owns the gate.
   Acquiring the exclusive lock proves only that no other session holds it, not that a predecessor process died.
3. When a rebuild or recovery is explicitly intended, run:

   ```sh
   docker compose exec -T app php artisan payments:projections:rebuild --confirm-rebuild
   ```

   Preserve stdout JSON, exit status, and `operation_id`; use that ID to correlate intent, completion, or abort logs and the run's owner token.
   The command decides recovery from durable state; there is no `--recover` override.
4. Follow the result table, then run fresh verification once the gate is ready.
   Guarded recovery only establishes that no destructive clear committed; it is not an audit of pre-existing correctness.
   A rebuilt read model does not repair a missing or corrupted outbox envelope, so verification can legitimately still require targeted repair.

| Result | Operator action |
|---|---|
| Exit `0`, `rebuild_complete`, `guarded_recovery: false` | Full replay completed and set ready; verify the result. |
| Exit `0`, `rebuild_complete`, `guarded_recovery: true` | An abandoned pre-clear run was recovered without replay; verify existing state. |
| Exit `1`, `confirmation_missing` | No mutation started; supply confirmation only if a rebuild is intended. |
| Exit `1`, `lock_timeout` | No claim or clear occurred; inspect active shared transactions or an exclusive owner and coordinate a later attempt. |
| Exit `2`, `ownership_lost` | This run stopped writing; inspect the current owner and durable state before any new attempt. |
| Exit `2`, `canonical_event_undecodable` | Investigate canonical corruption; repeating the same replay does not correct it, and manually setting ready would expose partial state. |
| Exit `2`, `database_error` or `statement_timeout` | Resolve the underlying failure, inspect control state and locks, then choose a new authorized attempt if needed. |
| Exit `2`, `commit_outcome_unknown`, or missing final JSON | Do not infer rollback or automatically retry; inspect durable state because the last commit may have landed. |
| Exit `2`, `intent_audit_failed` | This run made no ownership claim; repair the logging sink before another attempt. |
| Exit `2`, `audit_failed` | Ready already committed; preserve the reported progress, fix the audit sink, and verify instead of assuming replay failed. |

With `state = rebuilding` and no exclusive owner, a new authorized run takes ownership under a fresh token.
If inherited `cleared_at` is null, it may set ready without replay; if non-null, it must clear partial state and replay from zero, even when rows look complete.
Never reset the marker or owner fields manually to force the shortcut.
The gate remains closed after an aborted destructive run until a successful replay commits ready.

`PAYMENTS_REBUILD_BATCH_SIZE` defaults to 500 (1-1000); `PAYMENTS_REBUILD_LOCK_TIMEOUT_MS` and `PAYMENTS_REBUILD_STATEMENT_TIMEOUT_MS` each default to 5000 (1-600000), through `config/payments.php`.
Final JSON and completion/abort logs report `high_watermark`, `events_replayed`, and `batches_committed` as far as confirmed; they are not live per-batch telemetry or proof about an unacknowledged commit.
`guarded_recovery` indicates path selection and can also be true on a failed recovery attempt.
`make resilience-contract` additionally kills a real `payments:projections:rebuild` process with `SIGKILL` while it holds the `rebuilding` state, and proves the gate stays closed, defers traffic without budget, and reopens after an authorized recovery run.
No production-scale SLA, throughput figure, zero-downtime behavior, exhaustive kill matrix, or real-incident runbook rehearsal is claimed.
See the [T11 evidence table](../specifications/payments/verification-and-rebuild.md#t11-implementation-evidence).

## Messaging ownership

The initial logical queue is `payments-projection` on Redis, supervised by Horizon:

```text
outbox relay -> Redis payments-projection -> Horizon consumer -> payment_details
```

**Resolved by T7.** Three literal names, validated at container resolution by
`Modules\Payments\Infrastructure\Relay\RelayConfiguration` rather than read ad hoc: Redis connection
`payments` (bounded connect/read timeouts, `max_retries => 0` — the relay's own PostgreSQL-backed
backoff is the single retry owner), queue connection and queue name `payments-projection`
(`after_commit => false`, so `push()` — never `pushRaw()` — publishes inside the open transaction),
and cache store `payments-relay` (pinned to the same bounded `payments` Redis connection, never the
default cache store, for the scheduler heartbeat only). Horizon supervises this queue, and only this
queue, on its own separate `horizon` Redis connection — see the projection profile above.

SQS/DLQ is not a second copy of that queue. A later milestone selects a distinct durable workload,
defines its message and idempotency contract, and runs ordinary Laravel SQS workers on ECS.

Redis Pub/Sub supports Reverb fan-out and carries no durable job ownership. See
[Messaging and queues](../architecture/messaging-and-queues.md).

## AWS readiness gates

Before an application process is deployed, its owning milestone must define:

1. exact command, configuration, health, and graceful-shutdown contracts;
2. repository-defined validation and smoke evidence;
3. ECS task/service ownership, scaling, restart, and deployment-interruption behavior;
4. IAM, runtime configuration, and secret-delivery boundaries;
5. logs, metrics, alarms, and recovery/runbook evidence;
6. rollback and compatibility behavior for database or message changes.

For SQS additionally define visibility timeout, worker timeout, Standard/FIFO choice, retry/backoff,
DLQ `maxReceiveCount`, redrive ownership, and the relationship with application poison counters.
Horizon cannot manage SQS.

## Deploy ownership

- `ledgerflow-api` owns application source, Dockerfile, CI validation, image build, runtime
  commands, configuration contracts, health endpoints, and application tests.
- `ledgerflow-spa` owns the frontend artifact and frontend delivery checks.
- `ledgerflow-infra` owns ECR, ECS services, autoscaling, networking, RDS, ElastiCache, SQS/DLQ,
  CloudWatch, IAM/OIDC, environment configuration, and deployment topology.

Application images are immutable and identified by commit SHA/digest. Infrastructure consumes
those artifacts rather than building application source. See
[Deployment boundaries](../architecture/deployment-boundaries.md).

## Related

- [Five-minute demo](five-minute-demo.md) - the reproducible local walkthrough of these processes.
- [ADR 0004](../adr/0004-lock-through-push-outbox-relay.md) — the scheduler/relay decision record.
- [Development environment](../specifications/foundation/development-environment.md)
- [Outbox delivery](../specifications/payments/outbox-delivery.md)
- [Interfaces and realtime](../architecture/interfaces-and-realtime.md)
- [Messaging and queues](../architecture/messaging-and-queues.md)
- [Roadmap](../roadmap.md)
- [Laravel Horizon documentation](https://laravel.com/docs/13.x/horizon)
- [Amazon SQS dead-letter queues](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-dead-letter-queues.html)
