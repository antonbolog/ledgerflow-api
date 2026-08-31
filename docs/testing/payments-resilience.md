# Payments Resilience Test Plan

This plan maps the Payments specifications to executable evidence. Exact commands are established
by the Laravel foundation milestone and then recorded in `AGENTS.md`; this document does not invent
commands before the scaffold exists.

## Environment

- Pest is the test framework.
- Pure Domain tests do not require Laravel, PostgreSQL, or Redis.
- Integration and end-to-end tests use the project-defined PostgreSQL and Redis services.
- SQLite is not a substitute for PostgreSQL concurrency and advisory-lock behavior.
- Queue/database fakes are used only when the faked boundary is not the behavior under test.
- A future SQS workload receives its own transport and DLQ test plan; it does not replace this
  Redis projection suite implicitly.

## Affected routes

- `POST /api/payments` — authentication, validation, idempotency, atomic event/outbox persistence,
  transaction retry, and stable response replay.
- `GET /api/payments/{paymentId}` — projected state, pending state, tenant ownership, missing state,
  and rebuild gating.

## Initiate payment

- Unauthenticated requests return shared Problem Details.
- Key lengths at 0, 1, 255, and 256 bytes are covered.
- Amount values at 0, 1, `100_000_000`, and `100_000_001` and currency cases are covered before
  transaction entry.
- Sanctum users resolve one server-side merchant; two merchants prove isolation.
- A new key commits exactly one receipt, stored event, and outbox row.
- Identical retry returns exact stored status, body bytes, and `Location`.
- Conflicting retry returns `idempotency_payload_mismatch` and creates no durable state.
- Concurrent identical requests converge on one payment and one logical event/outbox message.
- Each allowlisted SQLSTATE retries the whole closure no more than three times.
- Retry uses fresh aggregate/event objects but stable immutable context.
- Business, integrity, validation, and unlisted database failures never retry.
- Exhausted contention returns `idempotency_contention` with `Retry-After`.
- Forced exception at every write boundary rolls back all three durable records.

## Outbox relay

- No eligible row exits without side effects.
- Rebuild gate defers work without consuming delivery attempts.
- Transport acceptance updates state through a non-deferred push.
- Transport rejection records failure metadata without changing `last_published_at`.
- Repeated rejection backs off but never marks poison exhaustion.
- Crash after acceptance and before commit causes safe duplicate delivery.
- Missing projector receipt after grace makes the row eligible again.
- Healthy backlog/redelivery never consumes poison budget.
- Fifth confirmed handler failure exhausts; hard worker crash does not.
- Operator retry resets confirmed-handler state only after correction.
- Stopped scheduler produces stale heartbeat and growing eligible age; restart resumes delivery.

## Projection consumer

- First delivery inserts `PaymentDetails`, projector receipt, and completion marker atomically.
- Duplicate leaves business state unchanged and repairs only a missing completion marker.
- Another source event colliding on `payment_id` fails visibly.
- Projection failure rolls back read row, receipt, and marker.
- Missing or malformed durable envelope becomes visible failure.
- Rebuild gate defers without consuming outbox delivery budget.
- Confirmed handler failure metadata remains separate from projection rollback.

**Evidence ownership, delivered by T8.** T8 owns the duplicate-delivery,
marker-repair, poison-exhaustion, and operator-retry evidence in this section and in the
confirmed-handler-failure bullets under **Outbox relay**, and proves rollback and redelivery through
a deterministic integration failpoint plus a real Horizon stop / backlog / restart smoke. Transport
failure never consumes projection failure budget: only confirmed handler failures count toward
exhaustion. The full barrier-coordinated SIGTERM/SIGKILL multi-process suite stays with T12.

## Query payment

- Owned projection returns `200` and projected business fields.
- Owned receipt without projection returns `202 projection_pending` and `Retry-After`.
- Missing or differently owned payment returns the same `404 payment_not_found` shape.
- Active rebuild returns `503 projection_rebuilding`.
- Shared advisory lock and protected query use the same PostgreSQL backend connection.

## Verify and rebuild

- Consistent projection passes verification.
- Events inside delivery grace are reported as in-flight.
- Missing receipt, receipt without row, orphan row, wrong source event, and field mismatch each fail
  without mutation.
- Missing, orphaned, and canonically mismatched outbox envelopes fail verification.
- Targeted repair reconstructs one envelope, derives completion from receipt, and cannot fabricate
  an idempotency receipt.
- Rebuild waits for a consumer transaction that started before the rebuild flag.
- Lock timeout clears no projection data.
- Pinned-session reconnect aborts rather than assuming the advisory lock survived.
- Replay uses bounded global-ID batches and recreates deterministic business state and receipts.
- Stale queued jobs after replay are ignored through recreated receipts.
- Kill after a committed batch leaves `rebuilding`; the next authorized run clears and restarts.
- Guarded recovery returns to `ready` without replay only when no projection mutation began.

## Critical end-to-end paths

1. Initiate -> stored event/outbox -> Redis -> Horizon consumer -> projected query.
2. Commit with Redis unavailable -> pending query -> restore Redis -> projection appears.
3. Concurrent identical initiation -> one stable payment identity.
4. Corrupt `PaymentDetails` -> verifier fails -> controlled rebuild -> verifier passes.
5. Corrupt one outbox envelope -> verifier fails -> targeted repair -> delivery converges.

## Failure injection

- Integration tests inject failures at named persistence and delivery checkpoints.
- A real transport push followed by an injected exception proves the publish-before-commit path.
- Process tests use synchronization barriers, then SIGTERM/SIGKILL real relay, consumer, and
  rebuild processes. Arbitrary sleeps are not accepted as coordination. That process suite is T12's;
  T8's crash evidence is the deterministic failpoint and live Horizon restart smoke named under
  **Projection consumer**.
- Production logic contains no environment-conditioned fault flags.

## Architecture rules

- Payments Domain cannot depend on Laravel, Eloquent, HTTP, Redis, SQS, or queue types.
- Only ADR-listed Spatie aggregate/event base types are allowed in Payments Domain.
- Raw concurrency SQL stays inside its focused Payments Infrastructure owner.
- Queries never rehydrate `PaymentAggregate`.

## Related

- [Payments specifications](../specifications/payments/README.md)
- [Messaging and queues](../architecture/messaging-and-queues.md)
- [Runtime contract](../operations/runtime-contract.md)
- [Roadmap](../roadmap.md)
