# 0003 — Arbitrate payment idempotency in PostgreSQL, with a targeted conflict target and an owned retry allowlist

- **Status** — Accepted.
- **Date** — 2026-08-14.
- **Decision owner** — AB.
- **Implemented by** — T6.
- **Amended by** - [ADR 0008](0008-payment-intent-arbitration-and-deadline.md): a new receipt is now arbitrated by the merchant/intent pair as its named conflict target; the named-target rule and the retry allowlist below stand.

## Context

An authenticated merchant user initiates a payment and may repeat the HTTP request because it did not
receive or trust the first response. Two identical requests must produce one payment, one stored
event and one outbox envelope; a reused key carrying a different payload must be refused rather than
silently answered with the wrong payment.

Rate limiting reduces request frequency but proves nothing about business identity. A Redis lock can
reduce contention but is not durable: a lock lost to eviction, failover or a partition would let two
requests both believe they won. Whatever arbitrates must be the same thing that durably stores the
result, in the same transaction, or a crash between the two re-opens exactly the gap the mechanism
was supposed to close.

T3 and T4 measured the stack this decision rests on. T5 created the schema. T6 wrote the first
producer for `payment_idempotency_receipts` and `payment_outbox`, which is when the remaining
questions became answerable by measurement instead of argument.

## Decision

**PostgreSQL is the authority.** One outer transaction, on one backend connection, opened by an owned
retry runner. Inside it:

1. Insert a **complete** receipt — status, exact response bytes and `Location` included — with
   `INSERT … ON CONFLICT (merchant_id, idempotency_key_hash) DO NOTHING RETURNING id`.
2. An empty return means this request lost. Load the committed winner; replay its stored bytes when
   the request fingerprints match, and fail with `409 idempotency_payload_mismatch` when they do not.
3. A winning insert builds a fresh `PaymentAggregate` and `PaymentInitiated` from an immutable
   context created **before** the transaction, and persists them.
4. One explicitly registered synchronous projector copies the just-written
   `stored_events.event_properties` into `payment_outbox`.
5. Receipt, stored event and envelope commit together, or none of them do.

### The receipt is written complete, before the aggregate

The response bytes are part of the arbitration, not a consequence of it. Because the winning insert
already carries the status, the body and the `Location`, a later identical request is answered by
reading one row — no re-derivation, no second serialization, and no dependency on the aggregate being
readable. `response_body` is `TEXT` rather than `JSONB` for the same reason: the contract is the exact
bytes the first caller received, and `jsonb` reorders keys and strips insignificant whitespace, so it
would return a different document that merely means the same thing.

### The conflict target is named, and a bare `ON CONFLICT DO NOTHING` would have been a defect

`payment_idempotency_receipts` carries **two** unique keys: `(merchant_id, idempotency_key_hash)`,
which this insert arbitrates on, and a global unique on `payment_id`.
`PostgresGrammar::compileInsertOrIgnore()` emits `on conflict do nothing` with **no** target, which
swallows both. A `payment_id` collision would then return zero rows, be indistinguishable from losing
the intended merchant/key race, find no winner, and be answered `503 idempotency_contention` — a
**retryable** answer to a **permanent** condition, which a well-behaved client retries forever.

With the target named, that same collision raises a real `23505`, which the retry allowlist correctly
refuses and the mapper renders as `500`. This was measured, not reasoned about: see **H-1** below.

### The retry allowlist is owned, not delegated

`40P01`, `40001` and `55P03` retry the whole closure at most three times; nothing else retries.
Laravel's `Illuminate\Database\ConcurrencyErrorDetector` is deliberately **not** used: it matches
`40001` by code and `40P01` only through the English message `deadlock detected`, and does not
recognise `55P03` at all — T3 measured it returning `false` for a real PostgreSQL 18.4 lock conflict,
which is the case this specification cares most about. A classifier that misses the one state we can
actually produce is worse than none, because it looks like coverage.

The SQLSTATE is read from `errorInfo[0]` with `getCode()` as fallback, after unwrapping
`DeadlockException` — which extends `PDOException` but carries no `errorInfo` of its own, so an
unwrapped read returns `null` and misclassifies a retryable failure as fatal. Both channels carry the
SQLSTATE as a **string**, so comparison against an integer silently never matches.

Laravel's inner transaction gets **one** attempt, because the budget lives in the runner's loop and
multiplying the two would give up to nine attempts instead of three. Exhaustion returns
`503 idempotency_contention` with `Retry-After: 1` and no partial state, rather than the raw database
exception — the SQL vocabulary stays in the log.

### The outbox payload is read back, never re-serialised

The projector reads `stored_events.event_properties` from the row the repository has just written, by
its primary key, inside the same transaction on the same connection. That makes jsonb-equality with
the stored event true **by construction**. A second serializer call would instead be an empirical
claim that a future normalizer change could quietly falsify. The cost is one primary-key `SELECT`.

## Alternatives considered

- **A Redis lock as the authority.** Rejected. It is not durable, and it cannot participate in the
  PostgreSQL transaction that stores the result, so a crash between lock and commit re-opens the gap.
  A Redis lock may later be added *in front of* this mechanism to reduce contention, but it can never
  be the thing that decides.
- **Rate limiting.** Rejected as a category error: it changes how often a client retries, not whether
  two retries mean one payment.
- **`SELECT` then `INSERT`.** Rejected. Two statements cannot arbitrate without either a race or a
  heavier lock; `ON CONFLICT` makes the database decide in one statement.
- **Delegating classification to Laravel's concurrency detector.** Rejected on measurement, above.
- **Re-serialising the event for the outbox payload.** Rejected: it converts a structural guarantee
  into a claim that needs a test to stay true.
- **`spatie/laravel-data` for the command context.** Rejected here; see the deferral criterion in
  [development environment](../specifications/foundation/development-environment.md).

## Measured evidence

Both hard stops were answered against PostgreSQL **18.4** in `ledgerflow_test`, default isolation
**read committed**, on 2026-08-14.

**H-1 — does `INSERT … ON CONFLICT (…) DO NOTHING` block on a concurrent *uncommitted* duplicate?**
**Yes.** Three connections and two OS processes: a writer holding an uncommitted receipt, a second
**process** issuing the same targeted insert, and a third connection watching the second in
`pg_stat_activity`/`pg_locks` — so "blocked" was observed from outside rather than inferred from a
timeout. The waiter reported `wait_event_type = Lock`, `wait_event = transactionid`,
`mode = ShareLock`, `granted = false`, waiting on **the writer's own transaction id**. It can
therefore only unblock when that transaction ends, so the outcome it observes is always a settled
one. After the writer committed, the waiter returned **zero rows** and its following `SELECT` read
the winner's receipt; after a rollback instead, it inserted its own row and `RETURNING` carried it.
**A zero-row return implies a committed, readable winner**, which is what step 2 above rests on.

The same probe committed `(merchant, K3, P3)` and then inserted `(merchant, K4, P3)` — a different
key with the **same** `payment_id` — through the targeted clause, and PostgreSQL raised
`23505 … violates unique constraint "payment_receipts_payment_id_unique"`. Not swallowed. That is the
empirical half of the conflict-target decision above.

**H-2 — does an unwrapped suite provision its own schema and actually commit?** **Yes, on both
halves.** The commit-boundary suite runs without `RefreshDatabase`, so `DB::transactionLevel()` is 0
on arrival and every write crosses a real `COMMIT`; a second backend, on a different pid, counts zero
rows while the transaction is open and the committed rows after it returns. The suite drops every
table — including `migrations` — proves they are gone, and restores them through the same
`migrate --force` a fresh checkout would run. That drop-and-restore probe was retired in ANT-19: the
suite now runs the idempotent migration and restores its own shared state in every test, independent
of suite order.

That suite is what proves the atomicity claim rather than restating it:

- receipt, stored event and envelope share **one `xmin`** — PostgreSQL's own record of which
  transaction wrote each tuple — and one `pg_backend_pid()`;
- a failure injected at `TransactionCommitting`, the last moment inside the transaction, leaves all
  three tables empty from the observer;
- a synchronous projector that throws *after* the envelope row exists also rolls all three back,
  which is what `catch_exceptions => false` buys;
- a **real** `55P03` from a bounded `lock_timeout` retries and commits one logical result, with
  distinct event objects across attempts carrying identical `eventId` and `occurredAt`;
- three real `55P03`s exhaust the budget and leave nothing behind.

**Not proved, and not claimed.** `40P01` and `40001` are on the allowlist and unit-classified, but
only `55P03` has ever been produced against a real database. Multi-process concurrency beyond H-1's
two-process probe belongs to T12. Nothing here is a load, throughput or index-plan claim.

## Consequences

- Idempotency is durable and survives any process crash, because the thing that arbitrates is the
  thing that stores.
- The receipts table carries the API response, which couples a persistence row to an HTTP contract.
  That is deliberate — it is what makes exact replay possible — but a change to the success body's
  shape becomes a data concern, not only a code one.
- Every request costs one extra `INSERT` and, on the winning path, one primary-key `SELECT`.
- The retry allowlist is repository-owned code that must be maintained as PostgreSQL evolves. An
  architecture test forbids reaching for Laravel's detector instead.
- Receipts are retained indefinitely in this slice; no pruning policy exists yet.
- `409` on a fingerprint mismatch means a client that legitimately changes a payload must also change
  its key.

## Related

- [Initiation and idempotency](../specifications/payments/initiation-and-idempotency.md) — the
  canonical contract this ADR decides the mechanism for.
- [Outbox delivery](../specifications/payments/outbox-delivery.md) — envelope ownership across T6, T7
  and T8.
- [ADR 0002](0002-payments-domain-event-sourcing-allowlist.md) — why the `Domain` layer may name only
  two Spatie classes, which is why the command context is a hand-written type.
