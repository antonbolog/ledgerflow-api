# Interfaces and Realtime

This document defines the ownership boundaries between the Laravel API, the merchant dashboard,
the internal admin panel, and realtime delivery. Exact route schemas belong in feature
specifications and generated API contracts once implementation begins.

## User interfaces

| Surface | Owner | Audience | Primary protocol |
|---|---|---|---|
| Merchant API (`/api/*`) | `ledgerflow-api` | The `ledgerflow-spa` BFF, on behalf of merchant users | HTTPS JSON |
| Merchant dashboard | `ledgerflow-spa` | Merchant users | Browser application |
| Admin panel (`/admin`), planned | `ledgerflow-api` | Authorized business operators | Filament server-driven UI |
| Realtime channels | `ledgerflow-api` | Authenticated merchant dashboard sessions | WebSocket/Reverb |

`ledgerflow-spa` never reads the database, Redis, queues, or AWS services directly. It consumes
stable API contracts and authorized realtime events.

## Authentication and authorization

Laravel Sanctum is the API authentication mechanism, and its **bearer-token foundation exists as of
T2**: `laravel/sanctum` 4.3.3 is installed, `personal_access_tokens` is migrated, merchant users
issue and resolve tokens, and `Modules\Merchant\Application\Identity\MerchantContext` answers which
merchant owns the authenticated request.

**T6 serves the first authenticated endpoint**, `POST /api/payments` behind `auth:sanctum`, and
proves real bearer-token authentication: a test issues a genuine token and sends it as an
`Authorization: Bearer` header rather than installing a user through `actingAs`, so the guard is
what resolves the merchant.

**The browser contract above the token is now decided and not yet implemented.**
[ADR 0006](../adr/0006-merchant-oauth-bff-authentication.md) selects Passport as the OAuth 2.0
authorization and resource server, the Authorization Code Grant with PKCE, and a confidential
first-party client owned by the Next.js BFF in `ledgerflow-spa`.
The BFF holds the access and refresh tokens in two separate `Secure` `HttpOnly` cookies, browser
JavaScript reads neither, and the BFF calls `/api/*` with `Authorization: Bearer` so the browser
never reaches a resource endpoint directly.
Merchant resource routes stay session-free; a dedicated `merchant_web` session guard serves only
OAuth login and authorization, and the planned Filament panel authenticates operators through a
separate guard over a separate identity provider.
The sequences, cookie attributes, lifetimes, refresh and retry rules, and the limitations Passport
does not close are canonical in
[merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md).

**Until ANT-29 replaces it, Sanctum above is the implemented mechanism.**
Token abilities remain unscoped, `sanctum.expiration` remains null so tokens do not expire, and
`config/sanctum.php` is published in full, so its `stateful`, `guard`, `expiration` and `middleware`
keys are still unreviewed defaults rather than decisions.
CORS and the deployment domains also remain unreviewed and are named as open items in that
specification.
None of them may be inferred from local development defaults.

Merchant authorization is tenant-scoped on the server. A merchant identifier supplied by a
client is never sufficient authority for accessing a payment.

Filament is planned and not installed. When it lands it uses a separately authorized admin access
path at `/admin`, authenticating operators through a separate identity provider rather than the
merchant provider that serves OAuth authorization; its session-cookie isolation is owned by that
panel's own contract. Model policies and explicit authorization protect both generated CRUD and
custom actions. Admin access does not imply DevOps or raw-data authority.

## Admin boundary

Filament is intended for business workflows such as:

- merchant and user support;
- payment search and read-only lifecycle inspection;
- explicitly authorized actions such as a future refund command;
- account restrictions and auditable business interventions;
- business-facing audit history.

Filament must not provide generic mutation of stored events, projections, outbox rows, payment
status, queue state, or AWS resources. A business action calls the same Application command and
domain invariants as an API action; it does not update event-sourced state directly.

Queue failures, DLQ redrive, Horizon operations, projection repair, and infrastructure state stay
in developer/DevOps tools unless a narrowly scoped operator workflow is separately designed,
authorized, and audited.

## WebSocket boundary

Laravel Reverb terminates WebSocket connections and authorizes private channels through the API
application. Redis Pub/Sub may fan broadcasts across multiple Reverb instances in local or AWS
topologies.

```text
ledgerflow-spa -> HTTPS API -> ledgerflow-api
ledgerflow-spa -> WebSocket -> Reverb -> authenticated channel
application event -> broadcast event -> Redis Pub/Sub -> Reverb instances -> browser
```

Redis Pub/Sub is transient fan-out. It is not a durable queue and cannot be the authority for a
payment transition. The browser must recover missed state through an API read after reconnecting.

**WebSocket authentication is deliberately deferred behind a separate contract.**
A browser WebSocket handshake cannot carry an `Authorization` header, so channel authorization
cannot reuse the BFF's bearer hop and would have to be proxied through the BFF instead. Reverb is
not installed and no merchant-facing realtime use case is specified, so the design is not invented
here. See
[merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md#deferred).

## Contract ownership

- `ledgerflow-api` owns request, response, error, authentication, authorization, and broadcast
  event contracts.
- `ledgerflow-spa` owns rendering, client state, interaction, and reconnect behavior.
- Cross-repository changes require a versioned or backward-compatible contract plus coordinated
  acceptance evidence.
- Realtime events accelerate the UI; they never replace the query API as the recovery path.

## Deferred decisions

- exact API versioning and generated client approach;
- local and production hostnames, CORS, and key management, listed as open items in
  [merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md#open-items-owned-by-the-implementation-tasks);
- Next.js rendering mode and hosting topology;
- WebSocket channel authentication, channel names, event payloads, and reconnect/backfill strategy;
- admin role model, MFA policy, and audit-log implementation.

These decisions move into feature specifications or ADRs when their implementation milestone
starts.

