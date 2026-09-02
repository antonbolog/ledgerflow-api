# Ledgerflow Documentation

This directory separates architectural explanation, executable specifications, delivery planning,
testing, and operations. A document should answer one class of question instead of accumulating
unrelated implementation details.

## Start here

| Question | Canonical document |
|---|---|
| Why does Ledgerflow exist and where are its boundaries? | [System overview](architecture/system-overview.md) |
| How do API, SPA, admin, and WebSockets interact? | [Interfaces and realtime](architecture/interfaces-and-realtime.md) |
| Which messaging capability owns each workload? | [Messaging and queues](architecture/messaging-and-queues.md) |
| Which repository owns build, deployment, and AWS configuration? | [Deployment boundaries](architecture/deployment-boundaries.md) |
| How does a payment move through the system? | [Payments flow](architecture/payments-flow.md) |
| What must be built, and in what order? | [Roadmap](roadmap.md) |
| How must local development work? | [Development environment](specifications/foundation/development-environment.md) |
| What is the exact Payments behavior? | [Payments specifications](specifications/payments/README.md) |
| How is resilience proved? | [Resilience test plan](testing/payments-resilience.md) |
| Which application processes run locally and on AWS? | [Runtime contract](operations/runtime-contract.md) |
| Why was a durable architecture choice made? | [Architecture decision records](adr/README.md) |

## Documentation rules

- Architecture documents explain boundaries, data flow, and trade-offs.
- Specifications define behavior and invariants that implementation and tests must satisfy.
- The roadmap owns delivery order and task tracking, not detailed runtime behavior.
- The test plan maps specifications to evidence without inventing commands before the scaffold.
- Operations documentation owns process topology, health evidence, and runtime readiness gates.
- Cross-repository system context is anchored here; SPA and infra implementation details remain
  authoritative in their own repositories.
- Durable decisions move into ADRs once their implementation is about to land.
- Every implementation task links to the smallest relevant specification instead of one monolithic
  plan.

## Delivery status

The write path, the scheduler-driven outbox relay, the Horizon-supervised projection consumer with
its failure accounting and operator retry, and the merchant-scoped read model query endpoint
(`GET /api/payments/{paymentId}`, projected `200` / pending `202` / `payment_not_found` `404`) are
implemented. The shared projection gate is not, so the `503 projection_rebuilding` state is
unreachable: T11 owns both. The [roadmap](roadmap.md) is the only canonical source for task history,
current sequencing, and completion status.
