# AGENTS.md — ledgerflow-api

Canonical repository instructions. Repository truth and the current user request override external
tool defaults. Keep this file short enough to remain useful as always-on context; durable detail
belongs in the linked documentation.

## Project

Ledgerflow is a production-like fintech learning and portfolio system built around DDD, CQRS,
event sourcing, idempotency, asynchronous delivery, and explicit failure recovery.

This repository owns the Laravel API, domain modules, internal Filament panel, application workers,
Docker image, and application runtime contracts. The merchant dashboard belongs to
`ledgerflow-spa`; Terraform, AWS resources, environments, and deployment topology belong to
`ledgerflow-infra`.

Core stack: PHP 8.4, Laravel 13, PostgreSQL 18, Redis 8, Passport, Spatie Laravel Event Sourcing,
`nwidart/laravel-modules`, Pest, PHPStan level 10, Pint, and Rector. Exact resolved versions and
compatibility evidence live in
[development environment](docs/specifications/foundation/development-environment.md).

## Current capabilities

- `Merchant` owns merchant-user identity and resolves the authenticated merchant through the
  Passport `api` guard. Sanctum is retired and its token table is dropped.
- The OAuth authorization server registers `GET /oauth/authorize` and `POST /oauth/token` and
  nothing else, from `routes/oauth.php`. The token endpoint carries neither session nor CSRF
  middleware and is throttled per client; authorize requests must carry an `S256` `code_challenge`.
  Merchants sign in on the `merchant_web` guard through a throttled login page, and that session is
  invalidated once an authenticated merchant has attempted one authorization, successful or refused.
  Consent is skipped for the one client id in `passport.bff_client_id` and never through Passport's
  prior-grant reuse; no consent screen exists, so any other client is refused.
- Refresh rotation revokes the presented token through one conditional update, so a concurrent loser
  receives `invalid_grant` instead of a second live token family. `POST /api/auth/logout` revokes the
  presented access token and its refresh token in one transaction and answers `204` with no body; it
  is not idempotent. `passport:purge` is scheduled daily and is bookkeeping only. There is no
  token-family tracking, replay detection, or cascading revocation.
- `Payments` is event-sourced. `POST /api/payments` atomically commits the idempotency receipt,
  stored event, and outbox envelope; retries replay exact stored response bytes.
  It requires `Ledgerflow-Intent-Id` and `Ledgerflow-Intent-Expires-At` and arbitrates by
  `(merchant_id, intent_id)`, so the same intent under a new key replays and conflicting reuse is
  `409`. One BFF-stamped expiry bounds every attempt and the single reservation rerun through a
  transaction-local `transaction_timeout` plus typed refusals; see
  [ADR 0008](docs/adr/0008-payment-intent-arbitration-and-deadline.md).
- The scheduler-driven outbox relay publishes eligible event IDs to the logical
  `payments-projection` Redis queue inside short PostgreSQL transactions. Its local scheduler is
  opt-in and exposes a read-only JSON status command.
- A Horizon-supervised consumer drains that queue in one deterministic PostgreSQL transaction: it
  locks the envelope, writes a projector receipt with `ON CONFLICT DO NOTHING`, inserts
  `payment_details`, and sets the completion marker together. A duplicate changes no business field
  and may repair only a missing marker; corruption and contradictory identity fail visibly.
- Confirmed deterministic failures are counted in a separate short transaction behind a monotonic
  `projection_failure_generation` fence; the fifth sets `exhausted_at`, and
  `payments:outbox:retry {eventId}` is the sole reactivation path. Backlog, locks, transport and
  commit uncertainty are never counted.
- The scheduler and Horizon are activated together through `make projection-start`/`projection-stop`
  and never one alone.
- `GET /api/payments/{paymentId}` is merchant-scoped through `MerchantContext`: owned projection ->
  `200` with exactly `payment_id`, `amount_minor`, `currency`, `status`, `initiated_at`; owned
  receipt without projection -> `202 projection_pending` with `Retry-After: 1`; otherwise
  `404 payment_not_found`, which malformed, unknown and foreign IDs all share. For one requested ID,
  unknown and foreign are byte-identical; a malformed ID shares the same problem type and code and
  differs only in the `instance` path it echoes. One adapter-owned transaction reads `payment_details`
  and, only on a miss, `payment_idempotency_receipts`; it never rehydrates `PaymentAggregate` and
  checks `payment_projection_controls` through the shared gate before those reads.
- `GET /api/payment-intents` is the merchant-scoped recovery read: `200` with
  `{"intents": [...], "truncated": <bool>}`, the merchant's intent-bearing receipts from the last
  24 hours of PostgreSQL time, newest first, at most 20. Projected elements add `amount_minor` and
  `currency` through a merchant-scoped join; pending ones omit both keys. The same adapter checks the
  shared gate first; absence counts only in an untruncated read, per
  [the recovery read](docs/specifications/payments/projection-and-query.md#recovery-read).
- Manual `payments:projections:verify` audits canonical and derived state in one read-only snapshot.
  `payments:outbox:repair {eventId} --delivery-quiesced` repairs one envelope, preserves existing
  exhaustion and failure history, and never rebuilds read rows. See the
  [operator procedure](docs/operations/runtime-contract.md#manual-verification-and-targeted-repair).
- A bounded batch rebuild gates POST, GET, relay, and consumer traffic. POST and GET return
  `503 projection_rebuilding`; relay and consumer defer without consuming failure budgets. Verify
  reports incomplete and repair refuses while the gate is closed, before scanning or canonical
  locking. Rebuild ownership and guarded recovery follow
  [ADR 0005](docs/adr/0005-payments-projection-gate-and-rebuild-ownership.md).
- [`docs/api/openapi.json`](docs/api/openapi.json) is the canonical wire contract for every `/api/*` route;
  `tests/Feature/OpenApiContractTest.php` asserts its parity against the real routes, real responses
  and `ProblemType`. [The five-minute demo](docs/operations/five-minute-demo.md) walks the healthy
  flow, a real Redis outage, deliberate corruption, read-only verification and an explicit rebuild
  through project-defined commands.
- The integration suites inject persistence and delivery failpoints in process against real
  PostgreSQL and Redis.
  CI runs these Pest suites; process-level outage, lifecycle, and signal rehearsals are no longer
  part of CI.
  Historical process evidence is retained in the
  [resilience test plan](docs/testing/payments-resilience.md#process-level-resilience).

## Architecture and boundaries

Modules use `Modules/<Context>/app/{Domain,Application,Infrastructure,Http}` plus `app/Providers`.
[ADR 0001](docs/adr/0001-custom-ddd-module-layout.md) defines the layout and
`tests/Architecture/ModuleBoundaryTest.php` enforces it.

- `Domain` is framework-independent except for the exact Payments event-sourcing allowlist in
  [ADR 0002](docs/adr/0002-payments-domain-event-sourcing-allowlist.md).
- `Application` owns use cases and ports; `Infrastructure` implements framework and persistence
  adapters; `Http` owns transport concerns.
- Modules must not carry `config/` or `routes/`; shared framework registration stays at the root.
- Do not invent lifecycle transitions, abstractions, DTO packages, transports, or infrastructure
  before a concrete use case needs them.
- Redis queues and SQS never dual-own one logical job. Redis Pub/Sub is fan-out, not durable queue
  storage. See [messaging ownership](docs/architecture/messaging-and-queues.md).

### Protected Payments concerns

Payments event schema, idempotency, transaction retry, outbox delivery, compensation, projection,
and replay/rebuild semantics require explicit review against the smallest relevant specification.

- Preserve one-connection transaction ownership for receipt, stored event, and outbox writes.
- Transport failure must not consume projection failure budget or exhaust an outbox row.
- Preserve the connection-affine shared gate on traffic paths and the rebuild's exclusive ownership fence.
  Takeover must preserve inherited `cleared_at`; a non-null marker requires full replay.
  See [ADR 0005](docs/adr/0005-payments-projection-gate-and-rebuild-ownership.md).
- Logs and errors must not expose raw idempotency keys, credentials, or sensitive payment payloads.

Additional scoped rules apply under `Modules/Payments/`, `.github/`, `tests/`, and `docs/` through
their nested `AGENTS.md` files. Each has a sibling `CLAUDE.md` adapter pointing to its canonical
instructions.

## Development contract

Local development is Docker-first and editor-neutral. Prerequisites are Docker with Compose v2,
GNU Make, and Git; no host PHP or Composer is used. The Makefile is the public interface for
environment lifecycle, database lifecycle, validation, and CI contracts.

| Target | Contract |
|---|---|
| `make install` | Build and start a clean checkout, install dependencies, ensure test DB, migrate; idempotent and never drops data |
| `make start` / `make stop` | Start or stop services without deleting data |
| `make destroy CONFIRM=destroy` | Remove only project containers, networks, and volumes |
| `make db-migrate` / `make db-seed` | Mutate only the allowlisted local development database |
| `make projection-start` / `make projection-stop` | Start or stop the scheduler and Horizon consumer together, never one alone |
| `make demo-token [EMAIL=...]` | Print one one-hour Passport bearer token for a seeded fixture merchant, on stdout only |
| `make demo-password EMAIL=...` | Set a seeded fixture merchant's password from a hidden interactive prompt |
| `make demo-bff-client` | Register the local BFF OAuth client and print its secret once |
| `make demo-status` | Show relay heartbeat and Horizon supervisor state, read-only |
| `make demo-corrupt PAYMENT_ID=... CONFIRM=corrupt-projection` | Delete one named `payment_details` row for the demo |
| `make quality` | Read-only Composer, Pint, Rector, and PHPStan checks |
| `make test` | Run Pest against `ledgerflow_test`; leaves PostgreSQL and Redis running |
| `make check` | Run the normal local validation contract: `quality` then `test` |
| `make test-unit` | Run the unchanged Unit suite without starting database services |
| `make test-integration` | Run the unchanged Integration, Feature and Architecture suites on the test database |
| `make build-runtime` / `make build-nginx` | Build production and web image targets without publishing |
| `make ci` | After `make install`, run quality, unit, integration and both image builds in order |

The five `demo-` targets are local development tooling for the
[five-minute demo](docs/operations/five-minute-demo.md).
Neither `make check` nor `make ci` invokes them or tests their shell refusal paths.

Persistent development data changes only through migrations and seeders, and is recovered with a forward
corrective migration or a data restore.
`make destroy CONFIRM=destroy` deletes all local project data and is an explicit option only when that
loss is accepted.
Destructive Artisan database commands run only against the recognized disposable test target, and each
CI run attempt uses its own Compose project.
See [database lifecycles](docs/specifications/foundation/development-environment.md#database-lifecycles).

Use only these repository-defined validation commands.
`make check` must not mutate source or the development database.
CI runs `make install` before separately named quality, unit, integration, runtime image and Nginx
image steps.
The workflow calls the same commands as `make ci`; see the
[CI contract](docs/specifications/foundation/development-environment.md#ci-contract).

## Working method and tools

General flow: Think → Plan → Build → Review → Test → Ship → Reflect. Before planning a non-trivial
change, inspect the working tree, relevant instructions, exact source, conventions, and tests.

- Orient before any `grep`, `rg`, `find`, or broad file read, including on narrow configuration or
  documentation tasks: query Codebase Memory for code structure and `graphify query` for
  architecture and documentation, then read only the exact slices they identify. Fall back to
  narrow search only when a query cannot answer, and say so.
- Use Codebase Memory for symbols, callers, dependencies, and impact analysis. Inspect exact source
  before edits or implementation claims; do not reindex when the index is already ready.
- Use Graphify for broad architecture, documentation relationships, communities, and visual graph
  exploration. When `graphify-out/graph.json` exists, query it rather than reading generated graph
  artifacts directly.
- Run `graphify update .` after a completed feature or material architecture, dependency, API-flow,
  ADR, or documentation change. Do not refresh it after routine edits. Commit source changes first,
  then refresh and commit the four curated graph artifacts separately so provenance points at the
  source commit.
- Use RTK for shell output and `rtk proxy` when exact uncompressed output is required. Use Context
  Mode for large logs, tests, JSON, web/API responses, and batch output.
- Claude Code is the primary Build/Review/Ship executor; GPT/Codex is a Think/Plan pairing partner.
  Repository evidence overrides tool-specific assumptions.

## Documentation ownership

- `ARCHITECTURE.md` — thin architecture navigator.
- `docs/roadmap.md` — milestones, task state, dependencies, and tracker links.
- `docs/architecture/` — boundaries and end-to-end flows.
- `docs/specifications/` — canonical behavior and invariants.
- `docs/testing/` — executable acceptance and failure evidence.
- `docs/operations/` — process topology, observability, and runtime readiness.
- `docs/adr/` — durable architecture decisions.

Prefer pointers over copied narrative. Implementation evidence belongs in canonical specs or ADRs,
not in this file or the root README.

## Maintenance

Keep instructions synchronized with actual repository state. Update the smallest canonical source
when behavior changes, keep instruction adapters aligned, and surface conflicts instead of choosing
silently.
Add another nested `AGENTS.md` only when a subtree has material local rules or recurring mistakes.
Do not claim a higher agent-setup level without executable evidence for its required capabilities.
