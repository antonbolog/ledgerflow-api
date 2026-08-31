# Messaging and Queues

Ledgerflow uses several Redis and AWS capabilities for different purposes. Similar infrastructure
names must not collapse distinct delivery semantics.

## Capability map

| Capability | Planned use | Durable authority | Supervisor/operations |
|---|---|---|---|
| Redis cache | Derived or reusable application data | Source database or recomputation | Application metrics |
| Redis locks/rate limits | Coordination and protection | PostgreSQL/domain state remains authoritative | Application metrics |
| Redis Pub/Sub | Reverb broadcast fan-out | API/query state | Reverb and Redis telemetry |
| Redis queues | Internal, recoverable asynchronous jobs | PostgreSQL event/outbox/job-specific state | Horizon |
| Amazon SQS | Separately owned durable AWS workloads | Domain/outbox state plus SQS delivery state | CloudWatch, DLQ, ordinary Laravel workers |

Redis Pub/Sub, Redis queues, and SQS are not interchangeable.

## Payments projection slice

M1–M5 use one logical `payments-projection` Redis queue supervised by Horizon:

```text
stored event + outbox commit
        -> scheduler-driven relay
        -> Redis payments-projection queue
        -> Horizon worker
        -> projector receipt + payment_details
```

PostgreSQL remains the authority. Redis failure can delay projection delivery, but it cannot erase
the payment event or the durable outbox intent. At-least-once delivery and duplicate handling are
part of the application contract.

The initial AWS deployment may keep this internal, recoverable queue on ElastiCache/Horizon. Its
production readiness requires the same process supervision, restart, alerting, backup authority,
and failure evidence expected from any application worker.

**Resolved by T7.** The relay side of this pipeline is live: a dedicated Redis connection `payments`
(bounded timeouts, zero client-side retries — the relay's own PostgreSQL-backed backoff is the single
retry owner), the queue connection and queue name both literally `payments-projection`, and
`after_commit => false` so a message is only ever visible after the publishing transaction commits or
not at all.

**Completed by T8.** Horizon supervises this queue, and only this queue: one supervisor named
`payments-projection` on the connection and queue of the same name, one process, `tries = 1`, and a
15-second worker timeout ordered strictly below the 90-second reservation and above the consumer's
2-second projection-local `statement_timeout`. Horizon keeps its own bookkeeping on a separate
`horizon` Redis connection so it never shares the relay's bounded transport. See
[outbox delivery](../specifications/payments/outbox-delivery.md#scheduler-contract),
[projection and query](../specifications/payments/projection-and-query.md#implementation-evidence),
and [ADR 0004](../adr/0004-lock-through-push-outbox-relay.md).

## SQS adoption boundary

SQS/DLQ is added for a distinct workload that benefits from AWS-native durable delivery,
visibility timeouts, redrive, and independently scalable workers. Candidate future use cases
include outbound merchant webhooks or payment-provider integration work, but the first concrete
workload must be selected and specified before implementation.

For every SQS queue, define:

- logical queue owner and message contract;
- Standard versus FIFO decision;
- idempotent consumer behavior;
- visibility timeout, worker timeout, retry, and backoff relationship;
- DLQ `maxReceiveCount`, redrive ownership, and application poison semantics;
- IAM boundary, CloudWatch metrics, alarms, and operator procedure.

Horizon does not supervise SQS. Ordinary Laravel SQS workers run as separate application processes
on ECS.

## Transport ownership rule

One logical job has one selected transport in one environment. Do not publish the same job to
Redis and SQS merely to demonstrate both technologies. If one business event legitimately feeds
two consumers, define two explicit integration messages, owners, idempotency contracts, and
failure policies.

Queue selection follows workload semantics:

- use Redis/Horizon when work is internal, low-latency, operationally recoverable, and backed by a
  durable application authority;
- use SQS/DLQ when AWS-native durability, independent scaling, long retry windows, redrive, or an
  external integration boundary materially helps;
- keep commands that must commit atomically with financial state inside the PostgreSQL
  transaction and transactional outbox rather than trusting a broker as the business authority.

## Failure ownership

No queue is treated as disposable merely because work can be replayed. Every logical queue must
define lag, failure, retry, poison, and recovery evidence.

- Horizon exposes Redis queue throughput, wait time, worker state, and failed jobs.
- CloudWatch exposes SQS age, depth, receive failures, and DLQ state.
- Application logs and metrics connect queue attempts to durable event/outbox identifiers.
- Filament and the merchant dashboard do not expose raw queue or DLQ controls.

## Evolution

Queue mapping is reviewed when a measured workload changes its latency, throughput, durability,
or operational requirements. A transport migration or new queue is an explicit architecture
decision, not a configuration-only change.

## Related

- [Payments outbox delivery](../specifications/payments/outbox-delivery.md)
- [Runtime contract](../operations/runtime-contract.md)
- [Payments resilience](../testing/payments-resilience.md)

