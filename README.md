# Ledgerflow API

Ledgerflow is a production-like fintech learning and portfolio system built around DDD, CQRS,
event sourcing, idempotency, asynchronous delivery, and explicit failure recovery.

This repository contains the Laravel API, domain modules, application workers, Docker image, and
runtime contracts, and owns the future internal Filament administration panel. It is the
architecture anchor for the three-repository Ledgerflow system.

## What exists today

- `POST /api/payments` initiates a payment for an authenticated merchant. The idempotency receipt,
  stored event, and outbox envelope commit in one PostgreSQL transaction or not at all.
- Identical retries replay the exact stored response; conflicting payloads return `409`; supported
  transient database failures retry as a whole and exhaust into `503`.
- API failures use RFC 9457 `application/problem+json`.
- A scheduler-driven outbox relay publishes eligible event IDs to the logical
  `payments-projection` Redis queue with PostgreSQL-backed transport retry state and a read-only JSON
  health surface.
- A Horizon-supervised consumer drains that queue in one deterministic PostgreSQL transaction,
  writing a projector receipt, the `payment_details` read row, and the completion marker together.
  Duplicates converge without rewriting business fields; confirmed deterministic failures are counted
  behind a monotonic generation fence and the fifth exhausts the envelope, with
  `payments:outbox:retry` as the sole reactivation path.
- The scheduler and Horizon are activated together and never one alone. The relay stays dormant until
  an operator starts the pair.
- `GET /api/payments/{paymentId}` returns an authenticated merchant's own payment: `200` with the
  projected fields, `202 projection_pending` with `Retry-After: 1` while the projection lags, and
  `404 payment_not_found` otherwise. A malformed, unknown, or another merchant's payment ID all get
  that same answer: for one requested ID, unknown and foreign are byte-identical, so the endpoint
  never reveals that a payment exists but belongs to someone else. The query reads only the read
  model and its idempotency-receipt fallback, and
  never rehydrates the aggregate.
- A connection-affine projection gate closes Payments traffic during a bounded batch rebuild: `POST`
  and `GET` return `503 projection_rebuilding` with `Retry-After: 1`, while the relay and consumer
  defer without consuming any delivery or failure budget.
- `payments:projections:verify` audits canonical and derived state read-only;
  `payments:projections:rebuild` and `payments:outbox:repair` are explicit operator actions that
  never run automatically.

The [roadmap](docs/roadmap.md) is the canonical source for delivery status and sequencing.

## Quickstart

Requires Docker with Compose v2, GNU Make, and Git. No host PHP or Composer is required.

```bash
make install   # build, install dependencies, start services, migrate
make check     # Composer + Pint + Rector + PHPStan + Pest
```

CI runs dependency setup, quality, unit tests, integration tests (including Feature and
Architecture), and separate production runtime and Nginx image builds.
See the [CI contract](docs/specifications/foundation/development-environment.md#ci-contract) for
commands and evidence boundaries.

The API is available at <http://127.0.0.1:8080> (loopback only). `GET /up` is the health endpoint;
`POST /api/payments` requires a Sanctum bearer token and an `Idempotency-Key` header, and
`GET /api/payments/{paymentId}` requires the same bearer token.

Run `make` to see the complete command surface. Destructive targets require target-specific typed
confirmation, for example `make destroy CONFIRM=destroy` and
`make db-reset CONFIRM=db-reset`.

To watch the whole slice work, including a real Redis outage, deliberate read-model corruption and a
controlled rebuild, follow the
[five-minute resilience demo](docs/operations/five-minute-demo.md). The exact wire contract of both
endpoints is published as [`docs/api/openapi.json`](docs/api/openapi.json).

## Architecture

The application uses bounded contexts with a custom DDD module layout:

```text
Modules/<Context>/app/
├── Domain
├── Application
├── Infrastructure
└── Http
```

- `Payments` is event-sourced and owns payment lifecycle, idempotency, outbox, projection, and
  rebuild semantics.
- `Merchant` owns merchant-user identity and conventional CRUD persistence.
- PostgreSQL is the durable authority for financial and delivery state.
- Redis supports internal recoverable queues, cache, coordination, and Pub/Sub; SQS is reserved for
  separately owned durable workloads.

See [ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) for module boundaries and
[messaging and queues](docs/architecture/messaging-and-queues.md) for transport ownership.

## Repository ecosystem

| Repository | Responsibility | Visibility |
|---|---|---|
| [`ledgerflow-api`](https://github.com/antonbolog/ledgerflow-api) | Laravel API, modules, Filament `/admin`, workers, and runtime contracts | Private now, public later |
| [`ledgerflow-spa`](https://github.com/antonbolog/ledgerflow-spa) | Next.js/React merchant dashboard | Private now, public later |
| [`ledgerflow-infra`](https://github.com/antonbolog/ledgerflow-infra) | Terraform, AWS resources, environments, and deployment topology | Private permanently |

Each repository is authoritative for its own implementation and local operating rules.

## Technology

Current application stack:

- PHP 8.4, Laravel 13, and Sanctum
- PostgreSQL 18 and Redis 8
- Spatie Laravel Event Sourcing for `Payments`
- Docker, GNU Make, Pest, PHPStan, Pint, Rector, and GitHub Actions

Horizon supervises the `payments-projection` queue. Remaining runtime capabilities arrive only with
an owning use case and contract: Reverb for authenticated realtime updates, SQS/DLQ for a distinct
durable workload, and ECS Fargate/RDS/ElastiCache/CloudWatch through `ledgerflow-infra`. OpenSearch
remains behind an explicit use-case and cost gate.

## User-facing surfaces

- `/api/*` — merchant-facing JSON API consumed by `ledgerflow-spa`.
- `/admin` — planned internal Filament workflows for authorized business operators.
- Authenticated WebSocket channels — planned realtime merchant updates through Reverb.

AWS, Horizon, queue, DLQ, outbox-repair, and infrastructure controls are developer/DevOps surfaces,
not general-purpose merchant or admin CRUD.

## Documentation

- [Architecture map](ARCHITECTURE.md)
- [Five-minute resilience demo](docs/operations/five-minute-demo.md)
- [OpenAPI artifact](docs/api/openapi.json)
- [Documentation index](docs/README.md)
- [Roadmap](docs/roadmap.md)
- [System overview](docs/architecture/system-overview.md)
- [Payments flow](docs/architecture/payments-flow.md)
- [Development environment](docs/specifications/foundation/development-environment.md)
- [Payments specifications](docs/specifications/payments/README.md)
- [Resilience test plan](docs/testing/payments-resilience.md)
- [Runtime contract](docs/operations/runtime-contract.md)
