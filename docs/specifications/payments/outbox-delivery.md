# Payments Outbox Delivery Specification

## Given

`PaymentInitiated` and its Payments outbox envelope are committed atomically in PostgreSQL. Queue
delivery is asynchronous and at-least-once.

## Problem

The application can crash before publication, after a broker accepts a message, or while a
consumer is processing it. A transport outage is not the same failure as a deterministic poison
message. Treating publication as completion can lose projections; retrying blindly can create
duplicates or exhaust healthy work.

## Decision

The outbox remains durable history. A short-lived scheduler-driven relay publishes an event ID,
and the consumer loads the canonical envelope from PostgreSQL. A named projector receipt is the
authoritative completion signal; `projection_completed_at` is only a denormalized active-index
marker.

## Envelope and active index

`payment_outbox` contains:

- event/message UUID as the primary key;
- event type and schema version;
- aggregate UUID and version;
- JSON payload and `occurred_at`;
- `available_at`, nullable `last_published_at`, nullable `projection_completed_at`, and nullable
  `exhausted_at`;
- separate publication, consecutive transport-failure, and confirmed projection-failure counts;
- separate last transport and projection errors;
- timestamps.

The hot-path partial index is `(available_at, event_id)` where
`projection_completed_at IS NULL AND exhausted_at IS NULL`. Eligibility also requires
`available_at <= now`.

## Initial relay algorithm

The first slice implements lock-through-push:

1. Begin a short transaction and acquire the Payments projection shared gate.
2. Select one eligible row `FOR UPDATE SKIP LOCKED`.
3. Publish a small job containing only the outbox event ID.
4. Update the relevant delivery state.
5. Commit.

The queue connection must publish immediately inside this transaction, not through an
after-commit callback. One row per transaction, short transport timeouts, a bounded batch, and a
maximum wall-clock runtime prevent unbounded database occupancy. Overlapping relay invocations are
safe because row locks, not scheduler uniqueness, provide correctness.

## State transitions

```text
eligible
  |-- transport rejected --> transport backoff ---------------------|
  |-- transport accepted --> wait for projector receipt             |
  |                           |                                      |
  |                           +-- missing after grace --> redeliver -|
  |                           +-- handler exception --> failure count
  |                                                        |
  |                                              threshold -> exhausted
  |                           +-- receipt exists --> completed
  |
  +-- explicit operator retry <----------------------------- exhausted
```

- Accepted publication increments observability count, clears consecutive transport failure, sets
  `last_published_at`, and schedules receipt reconciliation. It does not consume poison budget.
- Rejected publication records transport failure and bounded exponential backoff while leaving
  `last_published_at` unchanged. An arbitrarily long transport outage never sets `exhausted_at`.
- Missing receipt after the configured grace period makes an accepted event eligible again.
- Only a confirmed consumer-handler exception increments the poison count.
- Five confirmed handler failures are the initial exhaustion threshold. Worker termination,
  backlog, and missing receipt are not poison evidence.
- `payments:outbox:retry {eventId}` clears exhaustion after operator correction while retaining
  historical evidence in logs.

Initial timings are operational defaults, not domain rules: start with a 30-second reconciliation
grace, capped exponential backoff, and one application-level consumer attempt per published job.
The compatibility and load-test milestone may tune them without weakening acceptance tests.

## Scheduler contract

`payments:outbox:relay` is a short-lived command scheduled every second. Local development and the
resilience demo use a continuously running Laravel scheduler process. Production deployment must
supervise a scheduler process and interrupt the previous release after new code is live.

Scheduler heartbeat and oldest eligible outbox age must expose a stopped scheduler even when HTTP
and workers are healthy. See the [runtime contract](../../operations/runtime-contract.md).

## Queue transport policy

The logical queue is `payments-projection`. It uses Redis and is supervised by Horizon. PostgreSQL
outbox state and projector receipts remain authoritative for recovery.

### Redis + Horizon

- M1–M5 use Redis so the relay, duplicates, Horizon supervision, failed jobs, and local recovery are
  easy to observe.
- The projection job and envelope must not depend on Redis types.
- Redis remains non-authoritative; PostgreSQL outbox and projector receipts support reconciliation.
- The initial AWS topology may keep this internal, recoverable queue on ElastiCache/Horizon when
  worker supervision, alarms, restart, and recovery evidence are in place.

### SQS boundary

SQS/DLQ is planned for a distinct durable workload selected after M5. It is not a second copy of
`payments-projection`. Its own specification must define message ownership, idempotency,
visibility timeout, worker timeout, retry/backoff, DLQ semantics, IAM, CloudWatch alarms, and
redrive procedure. Horizon does not supervise SQS.

Do not publish the same logical job to Redis and SQS merely to exercise both technologies. A
legitimate fan-out requires separately named consumers, messages, idempotency contracts, and
failure ownership. See [Messaging and queues](../../architecture/messaging-and-queues.md).

## Evolution gate: claim/lease publishing

Lock-through-push is intentionally simple. Trigger a 12B architecture review when sustained
measurement attributes one of these failures to network time inside the locked transaction:

- relay-held connections cause database-pool saturation or more than 20% API p95 regression;
- oldest eligible age exceeds five seconds for five continuous minutes while the transport is
  healthy;
- safe relay concurrency cannot meet throughput after timeout, batch, and index tuning.

If confirmed, implement a short transactional claim with token/expiry, publish outside the
transaction, token-guarded finalization, expired-claim recovery, and crash tests after claim,
publication, and before finalization. A transient transport outage alone is not a trigger.

## Acceptance criteria

- Transport outage leaves the payment and outbox committed, queryable as pending, and recoverable.
- Rejected publication records retryable transport state without false completion or exhaustion.
- Crash after accepted publication and before commit produces a safe duplicate.
- Missing accepted work is reconciled from durable state.
- Confirmed poison failure is separated from crash, backlog, and transport failure.
- Completion removes the row from the partial active index only in the consumer transaction.
- Redis/Horizon queue supervision and recovery remain explicit in local and AWS runtime contracts.

## Related

- [Projection and query](projection-and-query.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Messaging and queues](../../architecture/messaging-and-queues.md)
- [Resilience test plan](../../testing/payments-resilience.md)
- [Laravel Horizon documentation](https://laravel.com/docs/13.x/horizon)
