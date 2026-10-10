# 0008 - Arbitrate payment initiation by merchant-scoped intent, bounded by one absolute deadline enforced in PostgreSQL

- **Status** - Accepted on 2026-10-10 by the ANT-35 Phase 2 review.
- **Date** - 2026-10-09.
- **Decision owner** - AB.
- **Implemented by** - ANT-35 Phase 2: the schema, intent arbitration, deadline enforcement, problem mapping, and wire contract.
  The recovery read is ANT-35 Phase 3.
- **Amends** - [ADR 0003](0003-postgresql-idempotency-arbitration.md): the arbiter of a new receipt is the merchant/intent pair, not the merchant/key pair; ADR 0003's named-conflict-target rule and its retry allowlist stand.

## Context

The shipped BFF derives `Idempotency-Key` from its session, so a new BFF session produces a new key for the same logical payment and the API would create a second payment.
Cross-session recovery needs a durable, client-generated payment intent that the initiation transaction itself arbitrates, and a guarantee of the form: an intent absent from an untruncated recovery read after a known time does not commit.

Before this decision nothing bounded the initiation transaction except Nginx `fastcgi_read_timeout 60s`, and Nginx giving up does not stop PHP-FPM, so a slow initiation could still commit after the client stopped waiting.
The BFF stamps one absolute expiry `E` at an intent's first claim and sends it unchanged on every retry and resume.
One request may run up to three transaction attempts in `RetryingAtomicTransaction` plus one reservation rerun, and `E` must bound all of them.
PostgreSQL time is the clock authority, and the projection gate must stay each attempt's first statement ([ADR 0005](0005-payments-projection-gate-and-rebuild-ownership.md)).

Three transaction-local candidates were measured against PostgreSQL 18.4 (`InitiationDeadlineCandidatesTest`):

| Candidate | Blocked statement | Idle in transaction past `E`, then `COMMIT` | Session |
|---|---|---|---|
| `statement_timeout` | `57014`, not retried | Commits after `E` | Survives |
| `lock_timeout` | `55P03`, retried; indistinguishable from contention | Commits after `E` | Survives |
| `transaction_timeout` | Session terminated | Terminated; nothing commits | Terminated; PHP sees `HY000`, never `25P04` |

Further measured facts:

- `statement_timeout` bounds each statement and restarts for `COMMIT`; `lock_timeout` bounds only lock waits.
- A fixed per-attempt bound is multiplied by the retry budget: three `55P03` at 400 ms took about 1228 ms.
- `transaction_timeout` set inside an open transaction counts from the `SET`, not from `BEGIN`.
- After termination, Laravel's rollback on the dead socket replaces the original exception with a `PDOException` (`HY000`), and the next query reconnects on a new backend.
- `now()` is frozen at transaction start; `clock_timestamp()` advances.
- Nothing runs between the end of the work closure and `COMMIT` except `TransactionCommitting` listeners.
- Locally, a `COMMIT` round trip took at most 0.62 ms, and re-check to observer-visible commit at most 1.27 ms (20 samples, uncontended).
- PostgreSQL and the PHP worker clocks differed by less than 0.06 ms locally (one Docker VM kernel clock).

## Decision

### Identity and schema

- The recovery identity is `(merchant_id, intent_id)`.
  `merchant_id` comes only from the authenticated merchant; `intent_id` is a client-generated canonical lowercase UUID that neither derives from nor reveals the `Idempotency-Key`.
- `POST /api/payments` requires `Ledgerflow-Intent-Id` (exactly one canonical lowercase UUID) and `Ledgerflow-Intent-Expires-At` (exactly one `Date.prototype.toISOString()` value, UTC with milliseconds).
  A missing, repeated, or non-canonical header is `422 validation_failed`, including a headerless retry of a receipt written before intents existed.
- One forward additive migration adds nullable `intent_id uuid` to `payment_idempotency_receipts`, the unique index `payment_receipts_merchant_intent_unique (merchant_id, intent_id)`, and the partial index `payment_receipts_merchant_recent_intent_index (merchant_id, created_at DESC, id DESC) WHERE intent_id IS NOT NULL`.
  The unique index is merchant-scoped, never global; historical rows keep a null intent and are not backfilled.
- Receipt `created_at` and `updated_at` come from PostgreSQL `statement_timestamp()`.

### Arbitration

Every attempt, inside the existing one-connection transaction that owns receipt, stored event, and outbox:

```
gate check (first statement; closed -> 503 projection_rebuilding)
combined lookup: merchant AND (intent = I OR key = K)
  2 distinct rows ............................ 409 (key and intent bound to different payments)
  1 row, intent = I, fingerprint equal ....... replay stored bytes, status, Location (even after E)
  1 row, intent = I, fingerprint differs ..... 409
  1 row, intent != I (key bound elsewhere) ... 409
  1 row, no intent, fingerprint equal ........ replay (written before intents; matched on the key)
  0 rows
    arm the deadline (refuse before work inside the margin)
    INSERT ... ON CONFLICT (merchant_id, intent_id) DO NOTHING
      inserted -> persist aggregate -> outbox -> pre-COMMIT re-check -> COMMIT -> 202
      no row   -> re-classify the committed winner -> replay or 409
      23505    -> another unique key collided -> roll back -> rerun the whole attempt once, same deadline
                  -> the rerun classifies the committed winner; a second collision is a payment_id collision -> 500
```

- **ADR 0003 amendment.** The conflict target of a new receipt is the merchant/intent pair.
  It stays named, never omitted, for ADR 0003's reason: an untargeted clause would swallow other unique violations.
  Because the arbiter is the only conflict target, any `23505` from the insert comes from the key or `payment_id` index by construction; the adapter maps it to the Application signal `ReservationRaced` without inspecting the constraint name.
- Both mismatch shapes reuse `idempotency_payload_mismatch`, whose title and detail now cover the key and the payment intent, because the shipped BFF settles only that code and `validation_failed` as rejected.

### Deadline: a server-side timer at `E`, plus typed refusals ahead of it

After classifying, and before any write, every attempt (including those of the rerun) does the following:

1. One statement computes `remaining = E - clock_timestamp()` and, only when `remaining > M`, arms `set_config('transaction_timeout', remaining, true)`.
   The same statement first sets it to `0`, because PostgreSQL does not restart a timer already running from a non-zero role, database, or server default; without that, the server-side stop would silently be the default.
   Reading and arming in one statement means a PHP stall between them cannot move the server-side stop past `E`.
2. When `remaining <= M`, the attempt is refused before any write, with a typed application exception and nothing armed.
3. The work runs.
4. As the attempt's last statement before `COMMIT`, the remaining time is read again; when it is `<= M`, the attempt is refused and rolled back on the live session.
   The re-check lives in the initiation attempt, not in a connection-global `TransactionCommitting` listener.
5. `COMMIT` is issued.
   If PHP stalls past `E` after a passing re-check, or any statement or idle time crosses `E`, PostgreSQL terminates the session and nothing commits.

`M` keeps a typed refusal and its `ROLLBACK` ahead of the timer, so the common expiry path is a clean application error on a live session; session termination is the backstop.
No setting is session-level; the timer dies with each attempt's transaction.
`statement_timeout` and `lock_timeout` are not part of the mechanism, because neither prevents a commit after `E`.
A lost connection is recognised by Laravel's lost-connection detection in Infrastructure and surfaced as `PaymentIntentSessionTerminated`, never by SQLSTATE.

### Worst case across attempts and the rerun

At most six transactions can run for one request: three attempts, then a rerun with a fresh attempt budget but the same `E`.
None of them can write unless its own arming statement saw `remaining > M`, and each is stopped server-side at `E`.
A retry therefore spends the one deadline instead of multiplying it, and a rerun after the deadline is refused before work.
A wait behind an in-flight insert of the same intent is bounded by `E` as well.
The latest durable commit for an intent is `E` plus the residual latency of a `COMMIT` already executing at `E`.

### Values

| Value | Value used | Kind | Basis |
|---|---:|---|---|
| `M`, refusal margin | 250 ms | Implemented | Exceeds measured refusal-to-rollback time by orders of magnitude; only shortens the usable window |
| `R`, residual `COMMIT` allowance | 1 s | Deployment assumption | Measured maximum 1.27 ms locally; covers storage latency and network distance not measured here |
| `S`, BFF-to-API skew allowance | 2 s | Deployment assumption | Measured PostgreSQL-to-PHP offset under 0.06 ms locally; the BFF clock is not measurable in this repository and is assumed NTP- or provider-synchronized within 2 s |
| `D`, BFF intent lifetime | 30 s | Required of the BFF | `E = first claim + D`; below the 60 s Nginx bound |
| Expiry cap | `D + S` = 32 s | Implemented | The API refuses `E > clock_timestamp() + 32 s` as `422`, which also rejects a BFF clock ahead by more than `S` |
| `N` | `D + S + R` = 33 s after first claim | Derived | An intent absent from an untruncated recovery read at BFF time `E + S + R` does not commit under the tested conditions |

The guarantee is stated relative to the stored `E`: absence at BFF time `E + S + R` implies PostgreSQL time of at least `E + R`, after which no commit for that intent can land.

### HTTP mapping

| Situation | Response |
|---|---|
| Committed winner for the intent with an equal fingerprint | Replay the stored response, even after `E` |
| Missing, repeated, malformed, or over-cap header | `422 validation_failed` |
| Crossed binding, key bound to another intent, or same intent with another fingerprint | `409 idempotency_payload_mismatch` |
| Deadline refusal, no winner, PostgreSQL time `< E + R` | `503 idempotency_contention`, `Retry-After` = whole seconds until `E + R`, at least 1 |
| Deadline refusal, no winner, PostgreSQL time `>= E + R` | `422 validation_failed` with an `intent_expires_at` error |
| Session ended by the deadline timer, including during `COMMIT` | `503 idempotency_contention`, `Retry-After: 1` |
| `payment_id` collision that survives the rerun | `500 internal_error` |

A refusal before `E + R` stays unsettled, because a concurrent request for the same intent may still commit until then; answering `422` there could settle as rejected an intent that has a payment.
A termination is ambiguous, because the `COMMIT` may have landed, so it also stays unsettled; the retry replays if it did.
A deadline termination happens at `E`, so less than `R` remains and `Retry-After: 1` already covers it.
No new problem code is introduced; the POST `503` documents a whole-second `Retry-After` of at least 1, while the GET responses keep the constant 1.

### Retry allowlist

The allowlist stays `40P01`, `40001`, `55P03`.
The mechanism raises no `57014`, PHP never sees `25P04`, and `HY000` must not be retried because it may follow a durable `COMMIT` and only occurs once the deadline is spent.
Deadline refusals are application exceptions, not SQLSTATEs.

### Cutover and deployment gates

- The guarantee covers only intents committed after every API worker runs this code.
  A rolling deploy lets old workers keep writing receipts without an intent, and those payments never appear in the recovery read; the API repository states this boundary, and the deployment topology that must honor it belongs to `ledgerflow-infra`.
  A retry of such a receipt that carries the intent headers replays it when the key and payload match, so a request straddling the cutover is not settled as rejected.
- The shipped BFF stamps `E = first claim + 15 min`, which the 32 s cap refuses.
  The `ledgerflow-spa` change to a 30 s lifetime must merge before this decision's API change merges, and must be deployed before any API build that enforces the cap is deployed.
- The guarantee becomes merchant-usable only once the later SPA recovery slice ships.

## Alternatives considered

- **`statement_timeout` with a pre-`COMMIT` re-check** - keeps the session and a visible SQLSTATE, but a PHP stall between the re-check and `COMMIT` commits after `E`; rejected.
- **`lock_timeout` with a pre-`COMMIT` re-check** - same late-commit window, bounds only lock waits, and its `55P03` is retried as ordinary contention; rejected.
- **`transaction_timeout` alone** - prevents late commits, but turns every expiry into a session termination with a replaced exception and no typed outcome; rejected in favour of typed refusals ahead of the timer.
- **Fixed per-attempt bounds** - multiplied by the three-attempt budget and the rerun; rejected.
- **A global unique intent index** - would make equal intent ids under two merchants collide and reveal each other; rejected for a merchant-scoped index.
- **A new `payment_intent_mismatch` problem code** - would strand shipped SPA intents as pending; rejected for the generalized `idempotency_payload_mismatch`.
- **Nginx arrival-time anchoring** - superseded by the BFF-stamped expiry.

## Consequences

- A late commit is prevented by PostgreSQL itself, independent of PHP scheduling after the last check.
- Normal expiry yields typed refusals on a live connection; only the stall backstop terminates a session, and Laravel reconnects on next use.
- Same-intent retries under a new key or a new BFF session converge on one payment, and conflicting reuse fails safely.
- Feature tests that wrap each test in one transaction keep a deadline armed by a request until the test ends, so scenarios that outlive an expiry belong in the real-commit Integration suite.
- `R` and `S` are deployment assumptions; before production they need evidence from the deployed topology (`ledgerflow-infra`).
- Not measured: `COMMIT` latency under load, a `COMMIT` racing the timer, and a real BFF clock.
  PostgreSQL holding interrupts while it writes the commit record is assumed, not measured here; `R` covers that window.

## Evidence

- `Modules/Payments/tests/Integration/InitiationDeadlineCandidatesTest.php` - candidate characterization, clocks, and residual latency, kept as compatibility evidence.
- `Modules/Payments/tests/Integration/InitiationDeadlineTest.php` - the production mechanism:
  - classify-then-arm order and the re-check as the last statement before `COMMIT`;
  - refusal before work in three windows, and a pre-`COMMIT` refusal on a live session;
  - a stalled `COMMIT` ended by the server;
  - a wait behind an in-flight same-intent insert bounded by `E`;
  - one deadline across retries, and a rerun refused once the deadline is spent;
  - a timer already running from a non-zero session default disarmed and re-armed at `E`.
- `Modules/Payments/tests/Integration/InitiationIntentArbitrationTest.php` - concurrent same-intent submissions under the same and a different key converging on one complete atomic initiation (`[1, 1, 1]` on one `xmin`), concurrent and sequential crossed bindings, the rerun after a key race, a surviving `payment_id` collision, and replay after expiry.
- `Modules/Payments/tests/Integration/IntentReceiptsTest.php` and `Modules/Payments/tests/Feature/PaymentsSchemaTest.php` - the combined lookup, the arbiter insert, PostgreSQL-time timestamps, and the schema shape.
- `Modules/Payments/tests/Feature/InitiatePaymentIntentTest.php`, `Modules/Payments/tests/Unit/PaymentIntentHeadersTest.php`, and `tests/Feature/OpenApiContractTest.php` - the HTTP contract, header grammar, problem mapping, and OpenAPI parity.

## Related

- [ADR 0003](0003-postgresql-idempotency-arbitration.md) - arbitration and retry allowlist, amended here.
- [ADR 0005](0005-payments-projection-gate-and-rebuild-ownership.md) - gate-first ordering preserved by every attempt.
- [Payment initiation and idempotency](../specifications/payments/initiation-and-idempotency.md) - the canonical contract.
