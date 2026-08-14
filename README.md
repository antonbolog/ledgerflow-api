# Ledgerflow API

Ledgerflow is a production-like fintech learning and portfolio system built around DDD, CQRS,
Event Sourcing, idempotency, asynchronous delivery, and explicit failure recovery.

This repository contains the Laravel API and the internal Filament administration panel. The
first vertical slice proves that payment initiation remains consistent under repeated requests,
queue redelivery, worker interruption, and read-model rebuild.

## Status

T1 and T2 are complete. The repository has its Docker runtime, Makefile command contract and
validation surface, the `Merchant` and `Payments` modules with enforced layer boundaries
([ADR 0001](docs/adr/0001-custom-ddd-module-layout.md)), merchant-user identity inside
`Modules/Merchant` behind a Sanctum-authenticated application contract, and two deterministic
seeded merchants and users.

T1–T5 are complete. The Payments `Domain` now exists: `PaymentAggregate` records an immutable
`PaymentInitiated`, admitted by
[ADR 0002](docs/adr/0002-payments-domain-event-sourcing-allowlist.md)'s exact two-class allowlist,
and four migrations create `stored_events`, `snapshots`, `payment_idempotency_receipts` and
`payment_outbox`. Merchant's `Domain` is still deliberately empty.

**No HTTP endpoint is served yet.** Only the first lifecycle transition exists, and nothing writes a
receipt or an outbox row: idempotency arbitration, transaction retry, the relay, projection and
rebuild all belong to T6 and later, described in [the roadmap](docs/roadmap.md). **T6 is next.**

## Quickstart

Requires Docker with Compose v2, GNU Make, and Git. **No host PHP or Composer** — everything runs
in containers.

```bash
make install   # build, install dependencies, start services, migrate
make check     # composer validate + Pint + Rector + PHPStan + Pest
```

`make install` leaves four healthy services and the API on <http://127.0.0.1:8080> (loopback only);
`GET /up` is the health endpoint. `make` on its own prints the full command surface. Destructive
targets require a typed confirmation: `make destroy CONFIRM=destroy`,
`make db-reset CONFIRM=db-reset`.

The Laravel foundation, Docker runtime, command contract, and validation surface are in place. The
Payments domain, Merchant identity, and the module layout arrive in later milestones — see the
[roadmap](docs/roadmap.md).

## Repository ecosystem

| Repository | Responsibility | Visibility |
|---|---|---|
| [`ledgerflow-api`](https://github.com/mythern/ledgerflow-api) | Laravel API, Payments and Merchant modules, Filament `/admin`, application workers, and runtime contracts | Private now, public later |
| [`ledgerflow-spa`](https://github.com/mythern/ledgerflow-spa) | Next.js/React merchant dashboard | Private now, public later |
| [`ledgerflow-infra`](https://github.com/mythern/ledgerflow-infra) | Terraform, AWS resources, environments, and deployment topology | Private permanently |

This repository is the current architecture anchor for the complete Ledgerflow system. Each
repository remains authoritative for its own implementation and local operating rules.

## Planned stack

- PHP 8.4 and Laravel 13 (image pinned to `php:8.4.24-fpm-alpine`; `composer.json` requires
  `^8.4.1`, the minimum the locked dependency graph resolves against)
- PostgreSQL locally and Amazon RDS for PostgreSQL on AWS
- Redis locally and Amazon ElastiCache for cache, locks, rate limiting, Pub/Sub, and Laravel queues
- Laravel Horizon for Redis queue supervision
- Amazon SQS and DLQ for separately owned durable workloads
- Laravel Reverb for authenticated WebSocket communication
- Laravel Sanctum for API authentication
- Filament at `/admin` for business administration workflows
- Spatie Laravel Event Sourcing for the Payments module
- Docker, Make, GitHub Actions, Amazon ECR, and ECS Fargate
- CloudWatch for runtime evidence and operational alerts
- Amazon OpenSearch Service as a later search/reporting option, subject to a cost and use-case gate

Redis queues and SQS are not duplicate transports for the same production job. Each logical queue
has one owner and one selected transport. Redis Pub/Sub used by Reverb is also distinct from both
queue transports.

## User-facing surfaces

- `/api/*` — merchant-facing JSON API consumed by `ledgerflow-spa`.
- `/admin` — internal Filament panel for authorized business operators.
- authenticated WebSocket channels — realtime merchant-facing updates through Reverb.

AWS, Horizon, queue, DLQ, outbox-repair, and infrastructure controls remain developer/DevOps
surfaces. They are not exposed through the merchant dashboard or general-purpose admin CRUD.

## Documentation

- [Architecture map](ARCHITECTURE.md) — compact navigation across system documentation.
- [Documentation index](docs/README.md) — where each project concern is documented.
- [Roadmap](docs/roadmap.md) — milestones, implementation tasks, dependencies, and tracker links.
- [System overview](docs/architecture/system-overview.md) — boundaries, sources of truth, and key
  design trade-offs.
- [Interfaces and realtime](docs/architecture/interfaces-and-realtime.md) — API, admin, SPA, and
  WebSocket boundaries.
- [Messaging and queues](docs/architecture/messaging-and-queues.md) — outbox, Redis/Horizon, SQS,
  and Pub/Sub responsibilities.
- [Deployment boundaries](docs/architecture/deployment-boundaries.md) — image, CI/CD, Terraform,
  and AWS ownership.
- [Payments flow](docs/architecture/payments-flow.md) — end-to-end write, delivery, projection, and
  query flow.
- [Development environment](docs/specifications/foundation/development-environment.md) — Docker,
  editor neutrality, and the intended Makefile interface.
- [Payments specifications](docs/specifications/payments/README.md) — canonical behavior of the
  first vertical slice.
- [Resilience test plan](docs/testing/payments-resilience.md) — executable acceptance and failure
  coverage.
- [Runtime contract](docs/operations/runtime-contract.md) — application process topology and
  operational evidence.
