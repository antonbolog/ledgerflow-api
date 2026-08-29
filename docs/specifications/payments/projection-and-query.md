# Payment Projection and Query Specification

## Given

The Payments relay delivers an outbox event ID at least once. PostgreSQL retains the canonical
envelope and stored event.

## Problem

A consumer may receive duplicates, crash after partial work, or encounter messages out of order.
The query API must distinguish a missing payment from a valid payment whose projection has not
caught up, without rebuilding the aggregate on reads.

## Decision

The consumer applies one deterministic projection transaction. A durable projector receipt is the
idempotency boundary. The query API reads only `PaymentDetails` and the merchant-owned idempotency
receipt.

**Nothing in this specification is implemented yet.** The consumer, its two tables, the projection
failure fence, and the operator retry command are accepted T8 decisions pending implementation; the
query contract is T9's and the gate is T11's. [The roadmap](../../roadmap.md) owns task state.

## Consumer transaction

Owned by T8. For `(projector_name, event_id)`:

1. Begin one explicit transaction on the injected default PostgreSQL connection and load the durable
   outbox envelope by `event_id` `FOR UPDATE`.
2. Validate that envelope and decode exactly the `payments.payment_initiated` schema version it
   declares.
3. Insert a projector receipt with `ON CONFLICT DO NOTHING`.
4. When the insert succeeds, derive all business fields from the event and insert uniquely keyed
   `PaymentDetails`.
5. Set the outbox `projection_completed_at` marker.
6. Commit receipt, read model, and completion marker together.

The transaction deliberately opens on the envelope row lock, **not on a gate**.
`payment_projection_controls`, its advisory-key derivation, and consumer deferral belong to T11, so
T8 must not add a provisional gate, control table, or advisory key. **T11 prepends connection-affine
gate acquisition and deferral semantics to this same transaction**, before the envelope lock and on
the same connection. That is the consumer side of the reciprocal obligation recorded as HS-1 in
[ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) and in
[verification and rebuild](verification-and-rebuild.md#t8s-reciprocal-obligation-the-consumer-arrives-without-a-gate-either).

An already-exhausted locked row is an observability-only no-op: no projection, no counter, no
marker mutation. Operator retry is the sole reactivation path. A missing outbox row is a typed
non-countable visible failure that emits only safe structured context and writes no orphan failure
metadata.

If the receipt already exists, verify that it identifies the same named projector and event, and
that the corresponding read row exists. Leave `PaymentDetails` unchanged and fill only a missing
completion marker. A receipt whose read row is missing is corruption for verification/rebuild, not
an excuse to silently recreate state during a duplicate delivery.

A `payment_id` or `source_event_id` collision from another source event represents contradictory
history and must fail visibly. Do not hide it with an upsert.

The projection derives `initiated_at` from event `occurred_at`; it does not use processing time,
randomness, or live mutable data. PostgreSQL `now()` is used only for the operational receipt and
completion-marker timestamps. Aggregate version is retained so later lifecycle events can
detect out-of-order delivery. The first-event slice does not build a generic deferral framework.

Projection delivery has **one application attempt** and does not reuse initiation's
`RetryingAtomicTransaction`. A serialization retry loop belongs to the write path that owns its own
idempotency receipt; here redelivery and outbox reconciliation own recovery.

## Durable projection schema

Accepted for T8, pending implementation, and pinned here so T9-T11 do not reverse-engineer the
migration. Exact key and constraint names are asserted in the Payments schema test.

`payment_projector_receipts`:

- `projector_name varchar(255)`;
- `event_id uuid`;
- `created_at timestamp(6) with time zone`;
- composite primary key `(projector_name, event_id)`.

It carries **no foreign key** to `payment_outbox` or `stored_events`: the outbox is rebuildable
delivery state and `stored_events` is vendor-owned, and the receipt must outlive both.

`payment_details`:

- `payment_id uuid` primary key;
- `source_event_id uuid` unique;
- `merchant_id bigint` with a restrictive merchant foreign key;
- `amount_minor bigint`;
- `currency char(3)`;
- `status varchar(32)`, whose only first-slice value is `initiated`;
- `aggregate_version bigint`;
- `initiated_at timestamp(6) with time zone`.

There is **no mutable `updated_at`**, so a duplicate delivery has nothing legitimate to touch.

The same forward migration adds `payment_outbox.projection_failure_generation`, a non-negative
integer defaulting to `0`, with named non-negative check constraints for it and for
`projection_failure_count`. The generation is a monotonic fence that lets a late failure recorder
tell a delayed delivery apart from a new operator retry cycle.

No secondary index is added in T8. The point lookups this slice performs are already served by the
primary and unique keys above; a later index requires measured query-plan evidence.

## Query contract

**Owned by T9, and the gate check inside it by T11.** T8 delivers neither; the contract is recorded
here because it constrains what the consumer must make durable.

`GET /api/payments/{paymentId}` is merchant-scoped and runs its gate check plus read on one short
PostgreSQL transaction:

- owned `PaymentDetails` -> `200 OK` with projected business fields;
- owned idempotency receipt without projection -> `202 Accepted`, `projection_pending`, and
  `Retry-After: 1`;
- no owned projection or receipt -> `404 payment_not_found`;
- active rebuild -> `503 projection_rebuilding` with retry guidance, reachable only once T11's
  rebuild and gate exist.

Another merchant's payment returns the same not-found shape. The endpoint never rehydrates
`PaymentAggregate` and never treats Redis/SQS state as proof that a payment exists.

## Failure handling

- Projection failure rolls back the read row, receipt, and completion marker together.
- Confirmed handler failure metadata is recorded separately from the rolled-back projection
  transaction.
- A hard worker crash leaves no false completion and relies on transport redelivery or outbox
  reconciliation.
- Work deferred by the rebuild gate does not consume transport or poison budgets. T11 owns that
  path.
- Stale messages delivered after a successful rebuild are ignored by recreated projector receipts.

**Crash evidence splits with T12.** T8 owns deterministic rollback and redelivery evidence from an
injected failpoint, plus a live Horizon stop, backlog, and restart smoke. T12 retains the
barrier-coordinated SIGTERM/SIGKILL process suite, so a literal process kill is not T8 acceptance.
See the [resilience test plan](../../testing/payments-resilience.md).

## Failure classification

Accepted for T8, pending implementation. Only a **confirmed deterministic handler failure** consumes
the projection failure budget.

Countable:

- envelope or event rejection, unsupported alias or schema version, malformed canonical payload;
- contradictory payment identity;
- deterministic failure inside the projector itself.

Not countable:

- worker termination, queue wait, and backlog;
- Redis or other transport failure;
- PostgreSQL connection loss, and transaction begin, commit, or rollback uncertainty;
- lock, serialization, deadlock, and statement-timeout errors;
- failure of the failure-metadata write itself;
- gate deferral, once T11 introduces it.

`last_projection_error` stores only a fixed safe category from a closed set. Logs carry the
exception class, SQLSTATE, and safe identifiers, never raw exception text, payload, credentials, or
idempotency keys. Database failures are classified by **SQLSTATE class and owned constraint
identity**, never by matching exception messages, so a driver wording change cannot silently move a
failure between budgets.

Failure metadata is written in a **separate short transaction, after the projection transaction has
been rolled back**, never inside it. Three rules bound that write:

- An unknown COMMIT outcome writes no failure metadata at all. The next delivery rereads canonical
  receipt, read-row, and marker state and decides.
- The recorder re-locks the outbox row and applies metadata only while the row is still incomplete,
  non-exhausted, and on the `projection_failure_generation` captured under the projection
  transaction's lock. Otherwise it is a safe logged no-op, so a late recorder can poison neither a
  winning duplicate nor a reset cycle.
- A failed metadata write leaves the row incomplete and non-exhausted, so delivery stays retryable
  rather than falsely counted.

The fifth confirmed failure sets `exhausted_at` in the same metadata transaction that raises the
count to five. Exhaustion belongs with the counter that triggers it; see
[outbox delivery](outbox-delivery.md) for the writer-ownership split.

## Operator retry

Accepted for T8, pending implementation. `payments:outbox:retry {eventId}` is the sole reactivation
path for an exhausted envelope, and it works under a lock on the outbox row.

It refuses without mutation when the event is unknown, already completed, or not exhausted. On an
exhausted row it clears `exhausted_at`, resets `projection_failure_count` to `0`, clears
`last_projection_error`, increments `projection_failure_generation`, sets `available_at = now()`,
and preserves publication and transport history. The historical failure survives in structured logs
and retained failed-job records.

Setting `available_at` is the **one narrow exception** to T7's relay ownership of that column: a
successful reset makes the corrected row immediately eligible again in the same transaction.
Ordinary transport scheduling stays the relay's, unchanged. See
[outbox delivery](outbox-delivery.md) for that ownership split rather than a restatement here.

## Acceptance criteria

T8:

- First delivery creates one receipt and one read row atomically.
- Duplicate delivery changes no business fields and may repair only a missing completion marker.
- A receipt without its read row, and a contradictory payment identity, both fail visibly.
- An exhausted row is a logged no-op; a missing envelope is a typed non-countable failure.
- Rollback and worker-crash paths leave the message retryable.
- Only confirmed handler failures count, and the fifth sets `exhausted_at`.
- A late recorder cannot charge a completed row or a newer failure generation.
- Operator retry resets poison state under a row lock and preserves transport history.

T9:

- Pending, projected, missing, and other-merchant HTTP states have stable contracts.

T11:

- The rebuilding HTTP state has a stable contract, and gate lock and protected work use the same
  PostgreSQL backend connection.

## Related

- [Outbox delivery](outbox-delivery.md)
- [Verification and rebuild](verification-and-rebuild.md)
- [Resilience test plan](../../testing/payments-resilience.md)
