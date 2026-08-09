# Ledgerflow Roadmap

Status: **Milestone 1 in progress.** T1 (Laravel foundation, Docker runtime, command contract,
validation surface, CI) is complete. **T2 is next** and owns module boundaries and merchant-user
identity.

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
  established.

### M2 — Blocking compatibility spike

Prove exact Laravel/Spatie/PostgreSQL/Redis assumptions before production schema is finalized.

- T3 — Prove Spatie atomic persistence and fresh-object transaction retry.
- T4 — Prove advisory-lock connection affinity and immediate Redis dispatch.

Exit: spike documentation records exact package APIs and database/transport behavior. Any failed
invariant triggers design revision before M3.

### M3 — Atomic payment initiation

Ship the synchronous write half without depending on queue availability.

- T5 — Implement initiation aggregate, event, schema, and invariants.
- T6 — Implement atomic idempotent payment initiation and Problem Details.

Exit: sequential/concurrent idempotency, mismatch, ownership, rollback, and allowlisted transaction
retry pass against PostgreSQL.

### M4 — Relay, projection, and query

Complete the asynchronous tracer bullet on Redis/Horizon.

- T7 — Implement scheduler-driven outbox relay and separated failure semantics.
- T8 — Implement idempotent projection consumer and marker self-healing.
- T9 — Implement owned projected/pending payment query.

Exit: POST -> outbox -> Redis -> Horizon consumer -> GET works, including outage, duplicate,
backlog, scheduler-stop, and poison-handler cases.

### M5 — Verification, rebuild, and resilience proof

Detect and repair derived-state corruption while keeping partial rebuilds invisible.

- T10 — Implement bounded projection/outbox verification and targeted repair.
- T11 — Implement connection-affine gated batch rebuild and recovery.
- T12 — Prove end-to-end and process-level resilience failures.
- T13 — Publish the five-minute resilience demo and operational guide.

Exit: the complete [resilience test plan](testing/payments-resilience.md) passes through
project-defined commands; the README demo works from a clean checkout.

## Planned system evolution after M5

These directions begin only after the Payments resilience slice is complete. They are not yet
decomposed into the active Linear/Asana task set.

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

- Build the Next.js/React dashboard in `ledgerflow-spa` after stable API contracts exist.
- Finalize Sanctum, CORS/CSRF, deployment domains, WebSocket authorization, and reconnect behavior.
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
| T2 | P1 | [System overview](architecture/system-overview.md) | Module boundary and tenant fixture tests |
| T3 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | Atomic rollback and fresh-object retry spike |
| T4 | P1 | [Outbox delivery](specifications/payments/outbox-delivery.md) | Connection-affinity and non-deferred Redis push spike |
| T5 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | Domain, schema, and boundary tests |
| T6 | P1 | [Initiation/idempotency](specifications/payments/initiation-and-idempotency.md) | PostgreSQL concurrency and HTTP contract tests |
| T7 | P1 | [Outbox delivery](specifications/payments/outbox-delivery.md) | Redis/PostgreSQL relay and scheduler tests |
| T8 | P1 | [Projection/query](specifications/payments/projection-and-query.md) | Duplicate, rollback, backlog, crash, and poison tests |
| T9 | P1 | [Projection/query](specifications/payments/projection-and-query.md) | Projected/pending/missing/rebuilding and ownership tests |
| T10 | P2 | [Verification/rebuild](specifications/payments/verification-and-rebuild.md) | Read-only corruption matrix and targeted repair |
| T11 | P1 | [Verification/rebuild](specifications/payments/verification-and-rebuild.md) | Lock race, batch, kill/restart, and replay tests |
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
