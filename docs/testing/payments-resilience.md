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
a deterministic integration failpoint.
The live Horizon stop/backlog/restart smoke and T12 signal matrix are historical evidence retired from CI by ANT-18.
Transport failure never consumes projection failure budget: only confirmed handler failures count toward exhaustion.

## Query payment

- Owned projection returns `200` and exactly the projected business fields.
- Owned receipt without projection returns `202 projection_pending` and `Retry-After`.
- Missing, malformed, and differently owned payment IDs return the same `404 payment_not_found`
  shape.
- Active rebuild returns `503 projection_rebuilding`. Delivered by T11: `QueryPaymentTest`, `answers 503 projection_rebuilding with Retry-After: 1 while the gate is closed`.
- Shared advisory lock and protected query use the same PostgreSQL backend connection. Delivered by T11: `PaymentQueryTest`, `holds the gate lock on its own connection while a read is refused by the control row`.

**Evidence ownership.** T9 owns the projected, pending, missing, malformed, and foreign-ownership
bullets, plus evidence that the `payment_details` lookup and its `payment_idempotency_receipts`
fallback run in one adapter-owned transaction; T11 additionally reads the control row through the gate.
**All five are delivered**; see
[implementation evidence](../specifications/payments/projection-and-query.md#implementation-evidence),
rows Q-1 to Q-14. T11 delivers the last two bullets through the named HTTP and PostgreSQL observer tests above.

## Verify and rebuild

Verification (T10):

- Consistent projection passes verification, reporting its high watermark and audit timestamp.
- Events inside delivery grace are reported as in-flight; the same delivery at or beyond grace is
  reported as overdue, and an exhausted envelope is reported separately from both.
- Missing receipt, receipt without row, orphan row, wrong source event, and field mismatch each fail
  without mutation.
- Missing, orphaned, and canonically mismatched outbox envelopes fail verification.
- Reverse scans over projector receipts, read rows, and envelopes find derived records that have no
  canonical source, without depending on the canonical pass reaching them.
- Each reverse pass keysets its own primary key, and a report is complete only when all four passes
  finished.
- Canonical events sharing one durable event id are found across batch boundaries without holding
  history in memory.
- Multiple batches with ID gaps prove strict keyset cursor progress and capped diagnostic retention.
- A late lower-ID commit does not retroactively enter a finished run's snapshot, proved by
  coordinated writes rather than sleeps.
- A real `statement_timeout` and a terminated backend both end the run as incomplete, and neither
  resumes on a fresh connection.
- Every reverse pass has a measured plan that reaches `stored_events` through an existing index and
  never reads the JSON payload.
- A real-service run proves non-mutation and read-only enforcement, not only a mocked no-write
  expectation.
- Invalid configuration and invalid invocation fail before scanning, and timeout or error paths never
  report a complete clean audit.

Targeted outbox repair (T10):

- Targeted repair reconstructs one envelope, derives completion from the named projector receipt, and
  cannot fabricate an idempotency receipt.
- Two repairs of the same missing envelope contend on the canonical stored-event row, and the second
  observes what the first committed instead of overwriting it.
- Intent and outcome are audited under one operation id; a failure of either record is reported with
  the mutation state rather than as a clean success or a clean rollback.
- Absent, ambiguous, or contradictory canonical identity refuses without mutation, and so does a
  missing delivery-quiescence acknowledgement.
- Repair preserves transport history, failure counts, `projection_failure_generation`, and
  `exhausted_at`, and creates or modifies no projector receipt and no read row.
- Canonical identity locking serializes two repairs of the same missing envelope, and a unique
  conflict refuses instead of removing the conflicting row.
- Every other envelope, canonical event, idempotency receipt, projector receipt, and read row is
  unchanged.

Rebuild (T11). **Implemented**, with owning tests and evidence limits in the
[T11 evidence table](../specifications/payments/verification-and-rebuild.md#t11-implementation-evidence), under
[ADR 0005](../adr/0005-payments-projection-gate-and-rebuild-ownership.md).
The following contracts are delivered by `ProjectionRebuildTest` unless another suite is named:

- Rebuild waits for a consumer transaction that started before the rebuild flag.
- Lock timeout clears no projection data and writes no control-row column.
- Pinned-session reconnect aborts rather than assuming the advisory lock survived, **including while
  the run's own owner token still matches because no takeover has happened**. A token-only fence is the
  non-vacuous control: it must be observed to let the same run proceed.
- A rebuild whose `COMMIT` response is lost reports an unknown outcome, never a rollback, and recovery
  reads the durable row instead of trusting that report.
- Replay uses bounded global-ID batches over canonical `stored_events` and recreates deterministic
  business state and receipts.
- Stale queued jobs after replay are ignored through recreated receipts, without draining any queue.
- Kill after a committed batch leaves `rebuilding` and a non-null `cleared_at`; the next authorized run
  clears and restarts.
- A takeover, and a run that fails before its own clear, both preserve an inherited `cleared_at`.
- Guarded recovery returns to `ready` without replay only when the durable row proves no clear was
  committed.
- Traffic reaching a closed gate is deferred or refused without consuming poison or transport budget,
  and a deferred delivery never reaches `failed_jobs`.
  Owning suites: `OutboxRelayTest`, `PaymentProjectionConsumerTest`, `InitiationCommitBoundaryTest`, and `QueryPaymentTest`.
- Verification reports `rebuild_in_progress`, incomplete exit `2`, without scanning; repair refuses with exit `1` before canonical locking.
  `PaymentProjectionVerifierTest` and `PaymentOutboxRepairTest` cover both gate halves, missing control rows, backend affinity, and successful operation after reopening.
- Corrupt read model -> failed verification -> bounded rebuild -> successful verification is covered by `ProjectionRebuildTest`, `gates verification and repair during a real rebuild and verifies the rebuilt canonical state`.

Commit uncertainty is injected after real commits at claim, clear, batch, ready, and guarded-recovery ready boundaries.
It proves the committed side of uncertainty and subsequent state-based recovery, not a lost network acknowledgement.
The lock-half test releases the lock on a healthy session while the token still matches and observes that the row-only condition would pass; separate backend-kill tests prove abort and durable recovery.
No production-scale SLA, throughput figure, zero-downtime behavior, OS-process kill matrix, or real-incident operator rehearsal is established by these tests.

**Evidence ownership.** T10 owns the two groups named above, and **both are delivered**, proved by
real-transaction integration suites that commit without `RefreshDatabase`, coordinate through
injected clocks and second-connection locks rather than sleeps, and replay the statements each
command issued through `EXPLAIN`. Plan evidence is a claim about plan shape and index availability
at fixture volume, not about speed or production scale; the repair lookup's cost is additionally
measured at 22k and 200k canonical rows and recorded in
[verification and rebuild](../specifications/payments/verification-and-rebuild.md). T11 delivers the rebuild group, while the
barrier-coordinated SIGTERM/SIGKILL process suite is historical T12 evidence, retired in ANT-18.
The [T10 evidence table](../specifications/payments/verification-and-rebuild.md#t10-implementation-evidence) maps these contracts to the actual tests and states the limits of their claims.
Final review additionally covers nil-UUID reverse cursors, combined transaction/audit failures, explicit repair isolation, invalid invocation/configuration, and refusal to borrow an outer transaction.
A green suite on small fixtures is not a scale claim; any index justified by later measurements remains a separate forward-migration decision.

## Critical end-to-end paths

1. Initiate -> stored event/outbox -> Redis -> Horizon consumer -> projected query.
2. Commit with Redis unavailable -> pending query -> restore Redis -> projection appears.
3. Concurrent identical initiation -> one stable payment identity.
4. Corrupt `PaymentDetails` -> verifier fails -> controlled rebuild -> verifier passes.
5. Corrupt one outbox envelope -> verifier fails -> targeted repair -> delivery converges.

Paths 1-4 were exercised end to end by the retired T12 process rehearsal (see **Process-level resilience**).
Current CI retains component coverage through the unchanged Pest suites, without executing these complete process scenarios.
Path 5 - targeted outbox repair - stays with the T10 integration suite.

## Failure injection

- Integration tests inject failures at named persistence and delivery checkpoints.
- A real transport push followed by an injected exception proves the publish-before-commit path.
- Historical process tests used synchronization barriers before signalling real relay, consumer, and rebuild processes.
  ANT-18 removed those scripts and their CI execution; deterministic in-process failpoints remain.
- Production logic contains no environment-conditioned fault flags.

## Process-level resilience

**Historical evidence, retired by ANT-18.**
The following table records the T12 process rehearsal delivered in commit `2521f80` and its subsequent refinements before ANT-18.
The shell harness and both disposable Compose contracts have been removed.
None of these process-level observations is a current executable CI claim.
Current CI runs the unchanged Pest suites, including real PostgreSQL/Redis integration, and builds the production runtime and Nginx images.
The present-tense descriptions in this historical table describe the old acceptance criteria and mechanisms.

| Acceptance criterion (ANT-14) | Evidence | Boundary of the claim |
|---|---|---|
| Critical `POST -> outbox -> Redis -> consumer -> GET` passes | Real bearer token, real `POST` (`202`), a one-shot relay pass, the Horizon consumer, real `GET` (`202 -> 200` with the exact projected fields). | One payment through the real HTTP endpoints and the real transport, not a load or cadence claim - the retired T8 projection rehearsal covered the live per-second scheduler. |
| Redis outage/recovery converges pending -> projected | `compose stop redis`; `POST` still commits; the relay cannot publish and consumes no transport or poison budget; `GET` is `202`. `compose start redis`; the relay republishes; `GET` is `200`. | A real transport-daemon outage, not an injected `RedisException`. |
| Concurrent identical `POST` produces one logical payment | A `SHARE`-lock barrier on `stored_events` admits the first request's idempotency reservation and blocks its stored-event insert; the second identical request is then launched and observed through `pg_blocking_pids()` to be blocked by the first request's own backend, on that uncommitted reservation. Only then is the barrier released -> both answer successfully with one `payment_id`, one stored event, one receipt, one outbox envelope. | Complements T6's PostgreSQL-arbitration evidence with two real request processes whose write transactions provably overlap. Backgrounding two clients would not: the same assertions hold for a sequential replay, so the overlap is constructed and observed rather than assumed. |
| Publish-before-commit is deterministic | A `SHARE`-lock barrier on `payment_outbox`; a real relay process; a real Redis `push()`; `SIGKILL` while the relay is blocked on its post-push acceptance `UPDATE` -> the envelope rolls back to eligible and a later pass converges to exactly one read row and one receipt. | The kill is gated on an observed `pg_stat_activity` lock wait; no sleep. `OutboxRelayTest` owns the same boundary in-process. |
| Worker crash is deterministic | An envelope-row-lock barrier; a real `queue:work` process; `SIGKILL` inside the projection transaction -> no receipt, no read row, no marker, no poison budget; redelivery then converges to one read row. | A real process kill, not T8's in-process commit-boundary failpoint. The interruption rolls the transaction back either way. |
| Graceful worker shutdown finishes its work | The same envelope-row-lock barrier and a real `queue:work` process run without `--once`; `SIGTERM` while it is blocked inside the projection transaction, then the barrier is released -> the job commits, one read row and one receipt, no poison budget, `GET` is `200`, and the worker exits by itself within a bounded reap. | The opposite outcome to the row above from the same barrier position, so the pair measures signal semantics rather than timing. Running without `--once` is what makes the exit evidence of the signal rather than of the command ending. A control run substituting `SIGKILL` leaves the marker unset and fails this row. |
| Rebuild crash is deterministic | A `SHARE`-lock barrier on `payment_details` holds a real `payments:projections:rebuild` process at the destructive clear with the `rebuilding` state already committed; `SIGKILL` while its backend is in that observed lock wait -> the `rebuilding` state survives; `POST` and `GET` are `503 projection_rebuilding`; the relay declines to publish an eligible row and the consumer defers, with no poison budget change and no `failed_jobs`; authorized recovery run(s) reopen the gate. | A real process kill, gated on an observed lock wait, not T11's `pg_terminate_backend` on a pinned backend. |
| A killed process leaks no advisory lock | While the rebuild is blocked at the destructive clear, `pg_locks` shows exactly one granted `ExclusiveLock` on the gate key (`classid` 7011, `objid` 1, `objsubid` 2); after `SIGKILL` the count returns to zero within a bounded wait, asserted **before** the recovery loop runs. | The pre-kill count is the non-vacuous control and pins the hardcoded key: a control run against a wrong `classid` fails there rather than letting the post-kill count pass against nothing. Placement matters - the recovery loop's three attempts would otherwise mask a slow release, and a container kill has to propagate a socket close before PostgreSQL drops the session lock. |
| Corrupt projection is detected and rebuilt | A deleted `payment_details` row fails `payments:projections:verify` (exit `!= 0`); the recovery rebuild restores the read model and verification then passes (exit `0`); `GET` is `200` again. | End-to-end restatement; T10 and T11 own the read-only corruption matrix and the rebuild algorithm. |
| No arbitrary sleep coordinates a crash | Every `SIGKILL` is gated on an observed `pg_stat_activity` lock wait or an observed control-row state. `sleep` appears only as a poll interval and the `SIGTERM -> SIGKILL` escalation gap. | - |
| Teardown leaves no orphan process or corrupted fixture | The `EXIT` trap force-removes the named crash containers that still exist, runs `down --volumes --remove-orphans`, and removes the fixture directory; the suite then asserts no crash container survived and the scheduler is not running. | Scoped to the disposable project; it never touches the development database. |
| A failed teardown cannot be reported as a pass | Teardown is mandatory, so each stage is attempted even after an earlier one fails, stays bounded, and records its own failure. A suite that passed but left a crash container, a Compose service, a volume or its fixture behind exits non-zero; a suite that already failed keeps its first cause. Diagnostics run best-effort and never skip the teardown they precede. | The exit status of the teardown commands, not an inventory of the Docker host. |

The historical process rehearsal set two documented operational dials for single spawned processes so a `SIGKILL` landed inside the
intended window: the relay's `statement_timeout` for the relay-crash step and the rebuild's
`batch_size` for the rebuild-crash step. Production code branches on neither.

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
