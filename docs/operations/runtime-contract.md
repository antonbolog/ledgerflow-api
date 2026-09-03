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

`PROJECTION_CONTRACT_DEADLINE_SECONDS` may shorten the work deadline but never lengthen it past 180;
a non-numeric or out-of-range value falls back to 180. `PROJECTION_CONTRACT_SELF_TEST=1` runs one
deliberate hang before anything is created, which is how the enforcement itself is evidenced without
waiting out the full budget.

The contract prints expanded diagnostics only on failure, tears the project down on every exit
including the deadline path, and runs as part of `make ci`. It proves paired startup, a backlog while the consumer is stopped that consumes no poison
budget, restart convergence, duplicate delivery converging to one receipt and one read row, and that
the paired stop reaches both processes.

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
- rebuild state, owner, high watermark, batch progress, and final result.

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
   `2` means an incomplete audit or invalid configuration; `3` means operational attention for overdue delivery, exhaustion, or future event time.
   An incomplete report never establishes that the remaining history is clean.
3. If a finding requires correction of one outbox envelope, run `make projection-stop`.
   Independently confirm every scheduler, consumer, manual delivery command and delayed failure recorder has finished, including other hosts.
   Stopping the local pair alone is insufficient; the acknowledgement flag does not discover workers.
4. Run `docker compose exec -T app php artisan payments:outbox:repair <event-id> --delivery-quiesced`, replacing `<event-id>` with the validated durable event UUID, not the payment ID.
   Preserve the JSON, exit status and `operation_id` for audit correlation.
5. Run a fresh verification and assess all remaining findings before resuming the pair with `make projection-start`.
   A corrected but unprojected envelope can remain overdue while workers are stopped; that alone does not mean repair failed.
   Repair does not clear exhaustion: only an explicitly chosen `payments:outbox:retry <event-id>` reactivates an exhausted envelope after its cause is corrected.
   A receipt without its read row still requires T11 recovery, not repeated envelope repair or manual replay.
6. When resumption is appropriate, verify again after delivery has had an opportunity to converge.
   Keep unresolved read-model corruption visible; T10 provides no shared gate or rebuild command.

Repair exit `0` means `created`, `repaired`, or `unchanged`, with the final log call succeeding.
Exit `1` is an expected refusal; exit `2` is invalid input, runtime/timeout failure, audit failure, or commit uncertainty.
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

- [ADR 0004](../adr/0004-lock-through-push-outbox-relay.md) — the scheduler/relay decision record.
- [Development environment](../specifications/foundation/development-environment.md)
- [Outbox delivery](../specifications/payments/outbox-delivery.md)
- [Interfaces and realtime](../architecture/interfaces-and-realtime.md)
- [Messaging and queues](../architecture/messaging-and-queues.md)
- [Roadmap](../roadmap.md)
- [Laravel Horizon documentation](https://laravel.com/docs/13.x/horizon)
- [Amazon SQS dead-letter queues](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/sqs-dead-letter-queues.html)
