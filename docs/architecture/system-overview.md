# Ledgerflow System Overview

Ledgerflow is a production-like learning and portfolio project. Its product is not a complete
payment platform; its product is executable evidence that the hard parts of an asynchronous
fintech backend behave correctly under failure.

The complete system is split into a Laravel API and internal admin panel, a separate merchant
dashboard, and separately owned AWS infrastructure. The first implementation slice remains
backend-first so its financial and resilience contracts exist before a UI depends on them.

## Problem

A convincing DDD/CQRS/Event-Sourcing showcase must demonstrate more than folders and diagrams. A
reviewer should be able to repeat a request, stop workers, redeliver work, corrupt derived state,
and rebuild it without creating contradictory business history.

The first slice therefore implements only this scenario:

1. An authenticated merchant user initiates a payment.
2. The API returns one stable payment identifier.
3. An asynchronous projection makes the payment queryable.
4. Retries, duplicates, worker failures, and projection rebuild converge on the same result.

## Bounded contexts

- **Payments** is event-sourced. The full future lifecycle is `Initiated -> Authorized -> Captured
  -> Settled/Failed -> Refunded`, but the first slice records only `PaymentInitiated`.
- **Merchant** uses ordinary Eloquent persistence. The first slice needs only pre-seeded
  `Merchant` and `User` identities with one merchant per authenticated user.

Each module uses `Domain / Application / Infrastructure / Http`. Domain code stays independent of
Laravel, Eloquent, HTTP, Redis, and queue types. The exact Spatie aggregate/event base types are a
documented Payments-only exception.

## Source-of-truth hierarchy

| Data | Role | Rebuildable? |
|---|---|---:|
| PostgreSQL `stored_events` | Business history and authoritative payment facts | No |
| Idempotency receipts | Exact HTTP replay contract for a merchant and key | No |
| Payments outbox | Durable, reconstructable delivery intent | Yes, from stored events through an audited repair |
| Projector receipts | Authoritative proof that a named projector handled an event | Yes |
| `payment_details` | Query-optimized read model | Yes |
| Redis or SQS message | Transient delivery attempt | Not treated as truth |

The outbox closes the database-to-queue gap. If the process commits the event but cannot publish a
job, the pending outbox row remains discoverable. At-least-once delivery is expected, so projector
receipts and database constraints make duplicates safe.

## System shape

```text
ledgerflow-spa -- HTTPS JSON --> ledgerflow-api /api/*
ledgerflow-spa -- WebSocket --> Reverb -- Redis Pub/Sub fan-out
business operators ----------> ledgerflow-api /admin (Filament)
                                  |
                                  +--> PostgreSQL / Redis / application queues

ledgerflow-infra -------------> AWS resources and deployment topology
```

The SPA and Filament are separate product surfaces. AWS consoles, Horizon, DLQ redrive, outbox
repair, and projection rebuild remain developer/DevOps tools rather than general admin features.

The Payments tracer bullet is:

```text
POST /api/payments
        |
        v
one PostgreSQL transaction
  idempotency receipt + PaymentInitiated + outbox row
        |
        v
outbox relay -> queue transport -> projection consumer
                                      |
                                      v
                       projector receipt + payment_details
                                      |
                                      v
                     GET /api/payments/{paymentId}
```

```text
Normal delivery:  event -> outbox -> transport -> consumer -> payment_details
Rebuild:          stored_events -> rebuild command ---------> payment_details
```

The normal path and rebuild path reuse the same deterministic projection application service.
Rebuild never republishes integration side effects.

## Chosen approach

The first slice uses a Payments-specific transactional outbox rather than direct event-store
catch-up.

### Why

- It makes the database-to-broker failure window observable and recoverable.
- It demonstrates relay crash recovery and idempotent consumption.
- It keeps the durable envelope independent of Redis queue implementation details.

### Trade-offs

- More schema, retry state, operator tooling, and tests than a local catch-up projector.
- The initial relay holds a database row lock through a bounded network push.
- The design is deliberately Payments-specific; no shared bus or outbox framework is justified by
  one use case.

For a future local projection with no delivery-learning or integration requirement, direct
event-store catch-up should be preferred instead of copying this outbox automatically.

## Repository boundary

- `ledgerflow-api` owns Laravel application code, Filament `/admin`, Docker development
  configuration, application images, runtime contracts, and transport adapters.
- `ledgerflow-spa` owns the Next.js/React merchant dashboard, frontend artifact, and client-side
  API/WebSocket behavior.
- `ledgerflow-infra` owns Terraform, ECS, RDS, ElastiCache, SQS/DLQ resources, IAM, CloudWatch,
  networking, and deployment topology.

The AWS target is ECS Fargate, RDS PostgreSQL, ElastiCache, selected SQS/DLQ workloads, ECR, and
CloudWatch with GitHub Actions OIDC. Application repositories build their own immutable artifacts;
infrastructure consumes them. No AWS resource is provisioned from this repository.

## Scope boundaries

The first slice excludes rich admin roles, capture, settlement, refunds, gateways, wallets,
balances, double-entry accounting, merchant CRUD UI, webhooks, OpenSearch, Kafka, RabbitMQ, CDC,
Debezium, and reusable CQRS infrastructure. Filament, Reverb, SPA integration, SQS/DLQ, and
OpenSearch belong to later milestones with their own use cases and acceptance evidence.

## Related

- [Payments flow](payments-flow.md)
- [Interfaces and realtime](interfaces-and-realtime.md)
- [Messaging and queues](messaging-and-queues.md)
- [Deployment boundaries](deployment-boundaries.md)
- [Payments specifications](../specifications/payments/README.md)
- [Roadmap](../roadmap.md)
- [Runtime contract](../operations/runtime-contract.md)
