# Interfaces and Realtime

This document defines the ownership boundaries between the Laravel API, the merchant dashboard,
the internal admin panel, and realtime delivery. Exact route schemas belong in feature
specifications and generated API contracts once implementation begins.

## User interfaces

| Surface | Owner | Audience | Primary protocol |
|---|---|---|---|
| Merchant API (`/api/*`) | `ledgerflow-api` | Merchant users and `ledgerflow-spa` | HTTPS JSON |
| Merchant dashboard | `ledgerflow-spa` | Merchant users | Browser application |
| Admin panel (`/admin`) | `ledgerflow-api` | Authorized business operators | Filament server-driven UI |
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

Everything above the token itself is still undecided, and **T6 deliberately decided none of it**.
Token abilities are unscoped, `sanctum.expiration` is null so tokens do not expire, and the SPA
cookie-versus-token mode, CORS, CSRF, and deployment domains remain open. `config/sanctum.php` is
published in full, so its `stateful`, `guard`, `expiration` and `middleware` keys are unreviewed
defaults rather than decisions.

**These now belong to [Merchant dashboard integration](../roadmap.md#merchant-dashboard-integration)**,
where the SPA and its deployment topology are finalized — not to "the task that serves the first
endpoint", which has been and gone without needing to answer them. They must not be inferred from
local development defaults.

A server-to-server test authenticating with a bearer token settles nothing about the SPA. It is
evidence that the guard works, not a selection of the session mode a browser client will use; the
cookie-versus-token choice is still open in both directions.

Merchant authorization is tenant-scoped on the server. A merchant identifier supplied by a
client is never sufficient authority for accessing a payment.

Filament uses a separately authorized admin access path at `/admin`. Model policies and explicit
authorization protect both generated CRUD and custom actions. Admin access does not imply DevOps
or raw-data authority.

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

## Contract ownership

- `ledgerflow-api` owns request, response, error, authentication, authorization, and broadcast
  event contracts.
- `ledgerflow-spa` owns rendering, client state, interaction, and reconnect behavior.
- Cross-repository changes require a versioned or backward-compatible contract plus coordinated
  acceptance evidence.
- Realtime events accelerate the UI; they never replace the query API as the recovery path.

## Deferred decisions

- exact API versioning and generated client approach;
- exact Sanctum domain and credential configuration;
- Next.js rendering mode and hosting topology;
- channel names, event payloads, and reconnect/backfill strategy;
- admin role model, MFA policy, and audit-log implementation.

These decisions move into feature specifications or ADRs when their implementation milestone
starts.

