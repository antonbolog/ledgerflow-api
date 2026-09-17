# Ledgerflow Architecture

Ledgerflow is a three-repository fintech system. `ledgerflow-api` owns the Laravel domain and
application runtime, `ledgerflow-spa` owns the merchant dashboard, and `ledgerflow-infra` owns AWS
infrastructure and deployment topology.

## System boundaries

- [System overview](docs/architecture/system-overview.md)
- [Interfaces and realtime](docs/architecture/interfaces-and-realtime.md)
- [Messaging and queues](docs/architecture/messaging-and-queues.md)
- [Deployment boundaries](docs/architecture/deployment-boundaries.md)

## Payments vertical slice

- [Payments flow](docs/architecture/payments-flow.md)
- [OpenAPI artifact](docs/api/openapi.json)
- [Payments specifications](docs/specifications/payments/README.md)
- [Resilience test plan](docs/testing/payments-resilience.md)

## Merchant authentication

- [Merchant OAuth and BFF authentication](docs/specifications/merchant/oauth-bff-authentication.md)
  - specified by ANT-28, not yet implemented; Sanctum remains in force until ANT-29.

## Runtime and delivery

- [Development environment](docs/specifications/foundation/development-environment.md)
- [Runtime contract](docs/operations/runtime-contract.md)
- [Five-minute demo](docs/operations/five-minute-demo.md)
- [Roadmap](docs/roadmap.md)
- [Architecture decisions](docs/adr/README.md)

This file indexes the architecture. Detailed behavior belongs in the linked canonical documents.
