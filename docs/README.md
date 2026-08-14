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

## Current status

The architecture and first Payments vertical slice are approved. **T1 and T2 are complete**: the
Laravel 13 skeleton, Docker runtime, Makefile command contract, and validation surface, plus the
`Merchant` and `Payments` modules with enforced layer boundaries, merchant-user identity behind a
Sanctum-authenticated application contract, and two deterministic seeded merchants and users.
`AGENTS.md` documents the commands that actually work, and
[ADR 0001](adr/0001-custom-ddd-module-layout.md) records the module layout.

T3 and T4 proved the blocking compatibility invariants, and T5 landed the production Payments
domain, the four Payments migrations and
[ADR 0002](adr/0002-payments-domain-event-sourcing-allowlist.md). Payments' `Domain` holds
`PaymentAggregate`, `PaymentInitiated` and two domain exceptions; Merchant's `Domain` remains
deliberately empty. No HTTP endpoint is served yet — **T6 is next**.
