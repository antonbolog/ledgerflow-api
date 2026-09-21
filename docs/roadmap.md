# Ledgerflow Roadmap

Status: **Milestones 1 through 5 complete.** T1 (Laravel foundation, Docker runtime, command contract,
validation surface, CI) and T2 (module boundaries and merchant-user identity) are done. T3 proved the
Spatie atomic-persistence and fresh-object retry invariants Q1–Q5; see the
[initiation evidence](specifications/payments/initiation-and-idempotency.md#blocking-compatibility-evidence).
T4 proved the advisory-lock and Redis-dispatch invariants Q6–Q10; see the
[outbox evidence](specifications/payments/outbox-delivery.md#blocking-compatibility-evidence).
**T5 is complete**: the production Payments domain, the four Payments migrations, the minimal
event-sourcing configuration, and [ADR 0002](adr/0002-payments-domain-event-sourcing-allowlist.md).
**T6 is complete**, closing M3: `POST /api/payments`, RFC 9457 Problem Details, and the first
producers of `payment_idempotency_receipts` and `payment_outbox`, recorded in
[ADR 0003](adr/0003-postgresql-idempotency-arbitration.md) with its evidence in the
[implementation evidence](specifications/payments/initiation-and-idempotency.md#implementation-evidence)
table. **T7 is complete**, opening M4: the scheduler-driven lock-through-push outbox relay, recorded
in [ADR 0004](adr/0004-lock-through-push-outbox-relay.md) with its evidence in the
[implementation evidence](specifications/payments/outbox-delivery.md#implementation-evidence) table.
**T8 is complete**: the Horizon-supervised idempotent projection consumer, its failure accounting and
generation fence, `payments:outbox:retry`, and the paired scheduler+Horizon activation, with evidence
in the
[implementation evidence](specifications/payments/projection-and-query.md#implementation-evidence)
table. **T9 is complete**, closing the read path: authenticated `GET /api/payments/{paymentId}` with
projected `200`, pending `202` and `payment_not_found` `404`, evidence in the same table. That
completes every M4 task; M4's `POST -> outbox -> Redis -> consumer -> GET` exit is **composed from
T6, T7, T8 and T9 evidence rather than one measured end-to-end run**, and the full-chain resilience
matrix stays [T12's](testing/payments-resilience.md). **T10 is complete and shipped** in pull
request #11, merged to `main` as `a41b1d6`: its contracts are
reconciled in
[verification and rebuild](specifications/payments/verification-and-rebuild.md),
`payments:projections:verify` and `payments:outbox:repair` are implemented, with an
[operator procedure](operations/runtime-contract.md#manual-verification-and-targeted-repair) and
[executable evidence](specifications/payments/verification-and-rebuild.md#t10-implementation-evidence).
The final validation checkpoint records the split CI execution and successful image-stage retry.
**T11 is complete and shipped** in pull request #12, merged to `main` as `28d3ad6`.
The control row, connection-affine gate on all four traffic paths, bounded batch rebuild, ownership
fence, guarded recovery, takeover, T10 verify/repair participation, and operator procedure are
implemented under [ADR 0005](adr/0005-payments-projection-gate-and-rebuild-ownership.md).
The final continuous `make ci` checkpoint passed before shipping.
**T12 is complete and shipped** in pull request #13, merged to `main` as `2521f80`:
The now-retired T12 process rehearsal proved the process half against real services, and its evidence and claim
boundaries live in the
[resilience test plan](testing/payments-resilience.md#process-level-resilience).
**T13 is complete and shipped** in pull request #15, merged to `main` as `051bf08`, closing M5:
the [five-minute demo](operations/five-minute-demo.md) and
[`docs/api/openapi.json`](api/openapi.json) are published through project-defined commands.

ANT-18 retires the shell CI harness and process rehearsals while retaining the implemented
application and all Pest suites.
References below to removed commands describe historical milestone evidence, not the current CI
contract.
The current stages and commands are defined in the
[development environment](specifications/foundation/development-environment.md#ci-contract).

## Outcome

Deliver one independently demonstrable Payments flow: an authenticated merchant user initiates an
idempotent payment; the receipt, event, and outbox commit atomically; asynchronous delivery creates
an eventually consistent read model; verification and controlled rebuild prove that derived state
can be detected and repaired after failure.

Each milestone leaves the repository documented and reviewable. A failed compatibility invariant
blocks dependent feature work instead of being hidden behind later tasks.

## Active delivery milestones

### M1 — Laravel foundation and executable contracts

Establish the framework, Docker environment, modules, authentication identity, and supported
project commands.

- T1 — Scaffold Laravel and establish executable project contracts.
- T2 — Establish module boundaries and merchant-user identity.

Exit, split by owning task so neither is reviewed against the other's clause:

- **T1** — a clean checkout starts through documented Make targets, and the project-defined
  validation contracts (`quality`, `test`, `check`) work. **Complete.**
- **T2** — deterministic merchant and user identities are seeded through the `db-seed` contract T1
  established. **Complete.**

### M2 — Blocking compatibility spike

Prove exact Laravel/Spatie/PostgreSQL/Redis assumptions before production schema is finalized.

- T3 — Prove Spatie atomic persistence and fresh-object transaction retry. **Complete.** Q1–Q5 all
  passed against Laravel 13.24.0, `spatie/laravel-event-sourcing` 7.15.1 and PostgreSQL 18.4. `40P01`
  and `40001` remain allowlisted but unmeasured, and multi-process concurrency is out of its scope.
- T4 — Prove advisory-lock connection affinity and immediate Redis dispatch. **Complete.** Q6–Q10 all
  passed against Laravel 13.24.0, PostgreSQL 18.4 and Redis 8.10.0 via phpredis 6.3.0: observer-witnessed
  lock/backend affinity, shared and exclusive contention with a real `55P03` from a bounded
  `lock_timeout`, silent reconnect outside a transaction versus surfaced loss and a stale
  `transactionLevel()` inside one, publication visible in Redis before the PostgreSQL commit, a
  non-vacuous `after_commit` control, and a crash that returns the row to eligible so a second identical
  attempt produces the safe duplicate. No worker, Horizon, scheduler, relay command, `SKIP LOCKED`, or
  multi-process behaviour was exercised, and the production advisory-key derivation stays with T11.

**M2 exit satisfied.** Spike documentation records the exact package APIs and database/transport
behavior, and no invariant failed, so M3 proceeds without design revision. T3's half is recorded in
[initiation/idempotency](specifications/payments/initiation-and-idempotency.md#blocking-compatibility-evidence);
T4's is in [outbox delivery](specifications/payments/outbox-delivery.md#blocking-compatibility-evidence),
with the gate consequences for M5 in
[verification/rebuild](specifications/payments/verification-and-rebuild.md#compatibility-evidence-for-this-gate)
and the resolved framework behavior in
[development environment](specifications/foundation/development-environment.md).

### M3 — Atomic payment initiation

Ship the synchronous write half without depending on queue availability.

- T5 — Implement initiation aggregate, event, schema, and invariants. **Complete.** `PaymentAggregate`
  and the immutable `PaymentInitiated` live in `Modules/Payments/app/Domain`, admitted by
  [ADR 0002](adr/0002-payments-domain-event-sourcing-allowlist.md)'s exact two-class allowlist.
  Four migrations create `stored_events`, `snapshots`, `payment_idempotency_receipts` and
  `payment_outbox`; a three-key `config/event-sourcing.php` pins the alias map and keeps handler
  exceptions visible. A real `persist()` proves the alias, the six-field payload, microsecond
  fidelity, deserialization of the readonly-promoted event, and that every required `payment_outbox`
  column is derivable from a persisted event.
- T6 — Implement atomic idempotent payment initiation and Problem Details. **Complete.** It owns the
  first writers for both `payment_idempotency_receipts` and `payment_outbox`. `POST /api/payments`
  commits the receipt, the stored event and the envelope in one transaction on one connection or
  none of them; an identical merchant/key/payload replays the exact stored bytes, status and
  `Location`; a mismatched payload is `409` and writes nothing new; the merchant comes only from the
  authenticated user; `40P01`, `40001` and `55P03` retry the closure at most three times with fresh
  aggregate and event objects, exhausting into `503` with `Retry-After: 1`; every `/api/*` failure is
  `application/problem+json`. The mechanism is recorded in
  [ADR 0003](adr/0003-postgresql-idempotency-arbitration.md). Delivery, projection and the read model
  were deliberately out of scope and stay with T7–T9.

**M3 exit satisfied.** Sequential idempotency, mismatch, ownership, rollback and allowlisted
transaction retry all pass against PostgreSQL, with commit-boundary claims proved from a suite that
runs without `RefreshDatabase` and reads from a second backend — see the
[implementation evidence](specifications/payments/initiation-and-idempotency.md#implementation-evidence).
Two limits are carried forward rather than closed: `40P01` and `40001` remain allowlisted and
unit-classified but have never been produced against a real database, and *concurrent* identical
requests are proved only across two connections in one process. Genuine multi-process racing is
[T12's](testing/payments-resilience.md).

### M4 — Relay, projection, and query

Complete the asynchronous tracer bullet on Redis/Horizon.

- T7 — Implement scheduler-driven outbox relay and separated failure semantics. **Complete.**
  `OutboxRelay` publishes one eligible row per short PostgreSQL transaction — `SELECT … FOR UPDATE
  SKIP LOCKED`, `push()` inside the open transaction with `after_commit => false`, then commit
  accepted state or capped-backoff transport-rejection state; every other failure rolls back and
  aborts the whole pass, tracked through explicit `transaction_started`/`commit_attempted` phases
  rather than inferred from end-of-request connection state. `RelayPaymentOutboxCommand`
  (`payments:outbox:relay`, scheduled every second) bounds one pass to a 50-row batch or a 750 ms
  start-budget checked before each next row. `PaymentOutboxStatusCommand`
  (`payments:outbox:status`) is a read-only JSON health surface combining a five-state scheduler
  heartbeat with one snapshot-consistent PostgreSQL aggregate, exiting `0` only when both are healthy.
  The scheduler ran locally as an opt-in Compose service behind profile `relay`, proven healthy
  against a disposable project — `make install`/`make start` never bring it up. (T8 moved it into
  the paired `projection` profile; this entry records T7's state as shipped.) Eight production
  types, one forward migration, no new Composer dependency. Recorded in
  [ADR 0004](adr/0004-lock-through-push-outbox-relay.md), which also records HS-1: **no shared
  projection gate is acquired**, deliberately deferred to T11's reciprocal obligation in
  [verification and rebuild](specifications/payments/verification-and-rebuild.md#t7s-reciprocal-obligation-discharged-by-t11-slice-2).
- T8 — **Complete.** The Horizon-supervised `payments-projection` consumer with marker self-healing;
  visible receipt-without-row, read-row-mismatch and contradictory-identity failures; the
  `payment_projector_receipts`/`payment_details` schema and a monotonic
  `payment_outbox.projection_failure_generation` fence; confirmed-failure accounting whose fifth
  failure exhausts durably in the same statement that writes the count;
  `payments:outbox:retry {eventId}` as the sole reactivation path; `laravel/horizon` v5.48.3
  supervising only the `payments-projection` connection and queue at one process, `tries = 1` and a
  15-second worker timeout, ordered strictly below the 90-second reservation and above the new
  2-second projection-local `statement_timeout`; and paired scheduler+Horizon activation after
  migrations through `make projection-start`/`make projection-stop`, proved end to end by
  the retired T8 projection rehearsal. Ten production types, one forward migration, one new Composer
  dependency. Evidence in
  [projection and query](specifications/payments/projection-and-query.md#implementation-evidence).
  **No shared projection gate was added**: T11 keeps the reciprocal obligation recorded in
  [verification and rebuild](specifications/payments/verification-and-rebuild.md#t8s-reciprocal-obligation-discharged-by-t11-slice-2).
  T8's crash evidence includes a deterministic in-process commit-boundary failpoint.
  Its live Horizon stop/backlog/restart smoke and T12's literal SIGTERM/SIGKILL process suite are
  historical evidence retired from CI by ANT-18.
- T9 — **Complete.** Authenticated `GET /api/payments/{paymentId}`, merchant-scoped through
  `MerchantContext`: an owned `payment_details` row returns `200` with exactly `payment_id`,
  `amount_minor`, `currency`, `status` and `initiated_at`; an owned idempotency receipt without a
  projection returns `202 projection_pending` with `Retry-After: 1`; anything else returns
  `404 payment_not_found`. **Malformed, unknown and foreign payment ids are one answer**: for one
  requested id, unknown and foreign produce byte-identical documents, and a malformed id produces the
  same problem type and code, differing only in the `instance` path it echoes. A malformed id is
  refused inside the query boundary before PostgreSQL sees an invalid UUID. The `payment_details`
  lookup and its conditional `payment_idempotency_receipts` fallback run
  in one short adapter-owned transaction on one injected connection object, reading no other table
  and writing nothing; **`PaymentAggregate` is never rehydrated and no stored event, outbox row,
  projector receipt or Redis key is consulted.** Six production types, one route, one scoped binding,
  one `ProblemType` case - no migration, index, dependency, configuration or process. Evidence in
  [projection and query](specifications/payments/projection-and-query.md#implementation-evidence).
  **No shared projection gate was added**: the `503 projection_rebuilding` branch and the shared-lock
  backend-affinity evidence stay T11's, per
  [projection and query](specifications/payments/projection-and-query.md#query-contract).

**What T7 did not prove.** *(Frozen as written at T7. T8 has since supplied the consumer, the Horizon
configuration and `payments:outbox:retry`; what T8 itself did not prove is recorded in
[projection and query](specifications/payments/projection-and-query.md#implementation-evidence).)*
No consumer, no Horizon configuration, and no `payments:outbox:retry`
exist. The relay stays dormant until an operator starts the scheduler and its Horizon-supervised
consumer together, which T8 makes possible. Pest never ran a live `schedule:work` process; the only
evidence that it drives the relay on a real per-second cadence is a disposable-Compose-project
measurement, not a Pest run, and that measurement's image came from an already-built tag rather than
a fresh `Dockerfile` build because of a registry-access limitation in that session — see
[development environment](specifications/foundation/development-environment.md#what-t7-added-and-what-it-deliberately-did-not).
No multi-process relay race, no load/throughput/index-plan claim, no rebuild-safety claim, and no
end-to-end projector-receipt reconciliation — none of the last four can exist before T8's consumer
and T11's rebuild gate do.

Exit: POST -> outbox -> Redis -> Horizon consumer -> GET works, including outage, duplicate,
backlog, scheduler-stop, and poison-handler cases.

### M5 — Verification, rebuild, and resilience proof

Detect and repair derived-state corruption while keeping partial rebuilds invisible.

- T10 - Bounded projection/outbox verification and targeted repair. **Complete**, shipped in pull request #11.
  Four read-only snapshot passes plus duplicate detection, and quiesced single-envelope repair preserving existing exhaustion and failure history.
  Contracts and measured limits live in [verification and rebuild](specifications/payments/verification-and-rebuild.md#t10-implementation-evidence); the [runtime contract](operations/runtime-contract.md#manual-verification-and-targeted-repair) owns the operator procedure.
  No new migration, index, dependency, recurring schedule, worker, projection gate, or read-model rebuild.
- T11 - Implement connection-affine gated batch rebuild and recovery. **Complete**, shipped in pull request #12.
  All five slices are implemented: the control-row migration, gate primitive, traffic acquisition
  on all four paths, bounded batch rebuild, ownership fence, guarded recovery, takeover, T10 command
  participation, and operator handoff.
  [ADR 0005](adr/0005-payments-projection-gate-and-rebuild-ownership.md) owns the gate key, the
  fail-closed control row plus advisory lock, the non-blocking traffic acquisition, the budget-free
  deferral, the lock-first acquisition order, and the ownership fence that rests on lock possession
  rather than on a token, a stored pid, or a successful query. Contracts are reconciled in
  [verification and rebuild](specifications/payments/verification-and-rebuild.md#projection-gate) and
  its [rebuild algorithm](specifications/payments/verification-and-rebuild.md#rebuild-algorithm).
- T12 — Prove end-to-end and process-level resilience failures. **Complete**, shipped in pull request #13,
  merged to `main` as `2521f80`.
  The integration half was delivered across T6-T11: transaction-lifecycle failpoints, a real Redis
  push followed by an injected commit-boundary exception, `pg_locks` barriers, and backend
  termination.
  The historical process half used a disposable isolated Compose project that drove
  the critical HTTP path, a real Redis outage, two identical `POST`s held in overlapping write
  transactions, `SIGKILL` of the real
  relay, consumer and rebuild processes, and `SIGTERM` of a real worker that must commit its
  projection and exit by itself - all under synchronization barriers, wired into the pre-ANT-18 CI.
  Both disposable-project contracts shared one bounded-deadline, scoped-teardown harness, removed by ANT-18.
  Post-merge review closed two evidence gaps: the concurrent `POST` is now barrier-coordinated and
  proves genuine transaction overlap, and a failed mandatory teardown can no longer be reported as a
  passing contract.
  Evidence and claim boundaries in the
  [resilience test plan](testing/payments-resilience.md#process-level-resilience).
  No production-code change; no new dependency, migration, or process.
- T13 - Publish the five-minute resilience demo and operational guide.
  **Complete and shipped** in pull request #15, merged to `main` as `051bf08`.
  The
  [five-minute demo](operations/five-minute-demo.md) drives the healthy flow, a real Redis outage and
  recovery, deliberate read-model corruption, read-only verification and an explicit rebuild through
  project-defined commands, with three guarded local `demo-` Make targets replacing the previously
  undocumented token and corruption steps. [`docs/api/openapi.json`](api/openapi.json) becomes the
  canonical wire contract, with parity asserted by `tests/Feature/OpenApiContractTest.php` inside
  `make test`. Local process topology is recorded in the
  [runtime contract](operations/runtime-contract.md#local-process-topology), and the stale T11 claims
  carried by the README, the documentation index, the payments flow and the ADR index are corrected.
  No production behavior, dependency, migration, or process was added.

At M5 shipment, the complete [resilience test plan](testing/payments-resilience.md) passed through
the then-current project-defined commands, and the README demo worked from a clean checkout.

**M5 exit satisfied at shipment.** T12 supplied the historical process resilience evidence, retired from CI by ANT-18, with its claim boundaries in the
[resilience test plan](testing/payments-resilience.md#process-level-resilience).
T13 carries the demo half through the
[five-minute demo](operations/five-minute-demo.md) and its guarded `demo-` Make targets.

## Planned system evolution after M5

The Payments resilience slice is complete, so these directions are now unblocked. They are not yet
decomposed into the active task set, except for merchant dashboard integration, which ANT-28 through
ANT-31 now carry.

### Filament admin and realtime foundation

- Add a separately authorized Filament panel at `/admin` for business administration workflows.
- Keep stored events, projections, outbox state, queues, DLQ, and AWS controls outside generic
  admin CRUD.
- Add Reverb and authenticated WebSocket channels when a merchant-facing realtime use case is
  specified.
- Use Redis Pub/Sub for Reverb fan-out without treating it as durable delivery.

### SQS/DLQ durable workload

- Select one distinct workload that benefits from AWS-native durability and redrive; candidate
  directions include merchant webhooks or payment-provider integration.
- Specify its message, idempotency, retry, visibility-timeout, poison, DLQ, redrive, IAM, and
  CloudWatch contracts.
- Run ordinary Laravel SQS workers as an independently scalable process. Horizon continues to
  supervise Redis queues and does not manage SQS.
- Never dual-publish the same logical job to Redis and SQS merely to use both technologies.

### Merchant dashboard integration

This direction is decomposed: ANT-28 specifies the authentication contract, and it blocks ANT-29 and
ANT-30 while relating to ANT-31.

- **ANT-28 is specification only and complete as documentation.**
  [ADR 0006](adr/0006-merchant-oauth-bff-authentication.md) and
  [merchant OAuth and BFF authentication](specifications/merchant/oauth-bff-authentication.md) record
  Passport as the OAuth 2.0 authorization and resource server, the Authorization Code Grant with PKCE
  behind a confidential first-party client, BFF-owned `Secure` `HttpOnly` token cookies, session-free
  merchant resource routes, and the deferrals for WebSocket authentication and password-change
  revocation. No code, dependency, migration, or wire contract changed.
- **ANT-29 migrates merchant API authentication from Sanctum to Passport.**
  The resource API now authenticates on the Passport `api` guard, Sanctum is removed and its token
  table dropped, and access, refresh and personal access lifetimes are configured. The authorization
  server now serves `GET /oauth/authorize` and `POST /oauth/token` only, enforces `S256` PKCE
  server-side, logs merchants in on `merchant_web` behind a throttle, spends that session on one
  authorization attempt, and skips consent for the one configured BFF client. Refresh rotation
  revokes through a compare-and-set, so the loser of a concurrent refresh receives `invalid_grant`
  rather than a second live family; `POST /api/auth/logout` revokes the presented access token and
  its refresh token in one transaction; and `passport:purge` is scheduled daily as bookkeeping.
  What ADR 0006 defers stands: no token-family tracking, no replay detection, no cascading
  revocation, and no password-change or WebSocket revocation.
- **ANT-30 scaffolds `ledgerflow-spa`** and establishes its executable contracts.
- **ANT-31 implements the PKCE flow in the Next.js BFF**, including the single-flight refresh that
  ADR 0006 leaves to it.
- Finalize CORS, deployment domains, WebSocket authorization, and reconnect behavior; the first two
  are open items named in that specification.
- Deploy and validate the frontend independently from the API image.

### AWS demo production

- Build API and SPA artifacts in their owning repositories and publish immutable images to ECR.
- Define Terraform, ECS Fargate, RDS, ElastiCache, SQS/DLQ, CloudWatch, IAM/OIDC, networking, and
  environment configuration in `ledgerflow-infra`.
- Run Redis/Horizon and any selected SQS worker as separate production processes with independent
  health and scaling evidence.
- Publish and smoke-test the complete deployed demo.

### OpenSearch evaluation

- Start only after a concrete search/reporting query and dataset make PostgreSQL projections
  insufficient.
- Estimate AWS cost and define indexing, replay, consistency, security, and recovery ownership.
- Adopt Amazon OpenSearch Service only if the measured benefit clears that gate.

See [interfaces and realtime](architecture/interfaces-and-realtime.md),
[messaging and queues](architecture/messaging-and-queues.md),
[deployment boundaries](architecture/deployment-boundaries.md), and the
[runtime contract](operations/runtime-contract.md).

## Delivery order

```text
T1 -> T2 -> T3 -> T4 -> T5 -> T6 -> T7 -> T8 -> T9
                                      |
                                      v
                              T10 -> T11 -> T12 -> T13
```

Implementation is intentionally sequential across schema and state-machine tasks. Independent
review and test-writing agents may inspect a completed milestone in parallel, but concurrent
worktrees must not modify the same Payments persistence contracts.

## Task contracts

| Task | Priority | Primary source | Required evidence |
|---|---:|---|---|
| T1 | P1 | [Development environment](specifications/foundation/development-environment.md) | Clean-checkout setup and full-check contracts |
| T2 | P1 | [System overview](architecture/system-overview.md) | Module boundary, layer dependency, identity, and deterministic seeding tests |
| T3 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | Atomic rollback and fresh-object retry spike |
| T4 | P1 | [Outbox delivery](specifications/payments/outbox-delivery.md) | Connection-affinity and non-deferred Redis push spike |
| T5 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | Domain, schema, and boundary tests |
| T6 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | PostgreSQL concurrency and HTTP contract tests |
| T7 | P1 | [Outbox delivery](specifications/payments/outbox-delivery.md) | Redis/PostgreSQL relay and scheduler tests |
| T8 | P1 | [Projection/query](specifications/payments/projection-and-query.md) | Duplicate, rollback, backlog, deterministic crash-boundary, Horizon restart, poison, and operator-retry tests |
| T9 | P1 | [Projection/query](specifications/payments/projection-and-query.md) | Projected/pending/missing/malformed and ownership tests |
| T10 | P2 | [Verification/rebuild](specifications/payments/verification-and-rebuild.md) | Read-only corruption matrix and targeted repair |
| T11 | P1 | [Verification/rebuild](specifications/payments/verification-and-rebuild.md) | Rebuilding HTTP contract, shared-lock backend affinity, lock race, batch, kill/restart, and replay tests |
| T12 | P1 | [Resilience test plan](testing/payments-resilience.md) | Full real-service resilience suite |
| T13 | P2 | [Runtime contract](operations/runtime-contract.md) | Clean-checkout five-minute demo |

## External trackers

- [Linear project](https://linear.app/anton-bolog/project/ledgerflow-316fa54d7b2b)
- [Linear architecture and delivery index](https://linear.app/anton-bolog/document/ledgerflow-architecture-and-delivery-index-3436164c1f5f)
- Asana project ID: `1217298144815270`

Linear and Asana intentionally mirror M1–M5 during the tool comparison. Neither tracker is the
architecture source of truth; repository documentation is canonical. Tracker descriptions must
link to the relevant document path and may not link tasks to each other across products.

Future cross-repository milestones identify their owning repository. Detailed SPA or Terraform
tasks must not be tracked as if their implementation belonged to `ledgerflow-api`.

## Deferred evolution

`TODOS.md` owns measured follow-ups: claim/lease outbox publishing, resumable zero-downtime rebuild,
and scheduled verification alerts. They must not be pulled into M1–M5 without their documented
trigger.
