# Ledgerflow API

Ledgerflow is a production-like fintech learning and portfolio system built around DDD, CQRS,
event sourcing, idempotency, asynchronous delivery, and explicit failure recovery.

This repository contains the Laravel API, its domain modules, application workers, Docker image,
and runtime contracts. The merchant dashboard lives in `ledgerflow-spa`; AWS infrastructure and
deployment topology live in `ledgerflow-infra`.

## Technology

- PHP 8.4, Laravel 13, Passport, and `nwidart/laravel-modules`
- PostgreSQL 18 and Redis 8
- Spatie Laravel Event Sourcing and Laravel Horizon
- Docker, GNU Make, Pest, PHPStan, Pint, Rector, and GitHub Actions

## Quick start

Requires Docker with Compose v2, GNU Make, and Git. No host PHP or Composer is needed.

```bash
make install   # build, start services, install dependencies, migrate
make check     # quality checks, then the Pest suite
```

The API is served at <http://localhost:8080>; `GET /up` is the health endpoint.
Run `make` to list every command.

The [development environment](docs/specifications/foundation/development-environment.md) is the
full development guide, and the [five-minute demo](docs/operations/five-minute-demo.md) walks the
running system end to end.

## Documentation

- [Documentation index](docs/README.md)
- [Architecture map](ARCHITECTURE.md)
- [OpenAPI contract](docs/api/openapi.json)
- [Roadmap](docs/roadmap.md)
- [Five-minute demo](docs/operations/five-minute-demo.md)
