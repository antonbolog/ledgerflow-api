# 0006 - Authenticate merchants with Passport OAuth 2.0 behind a Next.js BFF

- **Status** - Accepted and implemented in `ledgerflow-api` by ANT-29: the resource server (`api`
  guard, token lifetimes, Sanctum retirement, CORS), the authorization server
  (`GET /oauth/authorize`, `POST /oauth/token`, merchant login, server-side PKCE, the first-party BFF
  client), atomic refresh rotation, `POST /api/auth/logout`, and the daily `passport:purge`. The BFF
  half is ANT-31's; **D-16**'s deferrals stand.
  **Partly superseded** by [ADR 0007](0007-opaque-bff-session-custody.md): **D-4** in full, and
  **D-15** step 2 and its unconditional revocation claim. This record is kept as historical evidence
  and is not rewritten.
- **Date** - 2026-09-17.
- **Decision owner** - AB.
- **Implements into** - ANT-29 in this repository and ANT-31 in `ledgerflow-spa`.

## Context

**This section records the state at the time of the decision; ANT-29 has since implemented it.**

Merchant requests were authenticated by non-expiring, unscoped Sanctum personal access tokens,
resolved through the `sanctum` guard; see
[development environment](../specifications/foundation/development-environment.md#sanctum-as-actually-installed)
for what that actually installed. `config/auth.php` declared one guard, `web`, there was no login
route, and the Filament panel is planned but not installed.

`ledgerflow-spa` is at pre-scaffold stage, and the accepted direction is a Next.js runtime owning a
browser-facing backend for frontend. [Interfaces and realtime](../architecture/interfaces-and-realtime.md)
deferred everything above the token itself to
[Merchant dashboard integration](../roadmap.md#merchant-dashboard-integration), and that milestone is
now starting. Installing Passport or scaffolding the SPA before settling the authorization redirect,
the login boundary, token custody, refresh concurrency, CSRF, error semantics, and admin isolation
would create security-sensitive rework, so the contract is decided first.

Five properties of Passport were read from package and `league/oauth2-server` source before this
record, because each one constrains the decision rather than merely describing the package:

- `laravel/passport` accepts `illuminate/* ^13.0` from **v13.4.4** onward and currently resolves to
  **v13.8.0** on `php ^8.2`, so Laravel 13.24.0 on the pinned PHP 8.4.1 platform is in range.
- `GET /oauth/authorize` carries the `web` middleware only, and its controller resolves a
  `StatefulGuard` named by `config('passport.guard')`, redirecting a guest to a `login` route. A
  session-backed merchant login on the authorization server is structurally required, not a
  stylistic preference.
- `Client::skipsAuthorization()` returns `false` by default, so even a first-party client reaches a
  consent screen unless that default is changed.
- `RefreshTokenGrant` revokes the presented refresh token and its access token and issues a new
  pair, but **does not** detect replay of an already-rotated token and **does not** cascade
  revocation across a token family.
- `PassportServiceProvider` publishes its migrations and never loads them, exactly the trap Sanctum
  sprang in T2.

## Decision

**Passport is the OAuth 2.0 authorization and resource server, and the Next.js BFF is the only
browser-facing OAuth client.** The seventeen decisions below are fixed here. Every sequence, cookie
attribute, lifetime, status-code rule, limitation, and open item is canonical in
[merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md) and
is deliberately not restated in this record.

| # | Decision |
|---|---|
| D-1 | Laravel Passport is both the OAuth 2.0 authorization server and the resource server. |
| D-2 | The Next.js application in `ledgerflow-spa` is the BFF and the only browser-facing OAuth client. |
| D-3 | The flow is the Authorization Code Grant with PKCE, using a **confidential** first-party client. |
| D-4 | The BFF stores the access token and the refresh token in two separate `Secure` `HttpOnly` cookies that it owns. Superseded by [ADR 0007](0007-opaque-bff-session-custody.md) **S-1** and **S-5**: tokens live in a server-side BFF session record, and the browser holds one opaque session cookie. |
| D-5 | Browser JavaScript never receives either token. |
| D-6 | The BFF calls Laravel resource endpoints with `Authorization: Bearer`. |
| D-7 | The browser never calls a Laravel resource endpoint directly. |
| D-8 | Merchant resource endpoints remain session-free: no session, cookie, or CSRF middleware. |
| D-9 | A dedicated `merchant_web` session guard exists only for OAuth login and authorization. |
| D-10 | The planned Filament panel authenticates operators through a separate guard over a separate identity provider. |
| D-11 | Access tokens live 15 minutes; refresh tokens live 14 days and rotate on every use. Implemented by ANT-29 as a compare-and-set, so concurrent refreshes cannot both receive a successor pair. |
| D-12 | On `401` the BFF may refresh once and replay the original request at most once. |
| D-13 | The BFF never refreshes on `403`, on the tenant-hiding `404`, on `422`, or on `503`. |
| D-14 | No OAuth scopes are defined initially. |
| D-15 | Logout revokes the active access and refresh tokens, clears the BFF cookies, and ends the merchant authorization session. Amended by ANT-29: step 1 is `POST /api/auth/logout`, and step 3 needs no call because the merchant authorization session is one-shot and is invalidated as soon as an authenticated merchant has attempted one authorization, successful or refused. Step 2 is ANT-31's. Step 2 and the unconditional revocation claim are superseded by [ADR 0007](0007-opaque-bff-session-custody.md) **S-9**: logout always clears the one session cookie, while record termination, deletion, and revocation are best effort under dependency failure. |
| D-16 | WebSocket authentication and password-change token revocation are deferred behind separate contracts. |
| D-17 | Sanctum remains the implemented merchant authentication until ANT-29 replaces it. Superseded by ANT-29: Passport's `api` guard authenticates `/api/*` and Sanctum is removed. |

### Why a confidential client with PKCE rather than a public one

The BFF is a server process, so it can keep a client secret, and `passport:client --public` would
discard that capability for no gain. PKCE is applied regardless: it is what binds the authorization
code to the client that requested it, across a redirect the browser can observe. Client
authentication and PKCE are independent protections, and both are used.

### Why a dedicated `merchant_web` guard, and what it does not buy

Passport's authorize endpoint needs a stateful guard and `config('passport.guard')` defaults to
`web`. Naming `merchant_web` makes the guard that serves OAuth authorization explicit and reviewable
instead of tying it to whatever the application's default guard becomes once an admin panel exists.

**The guard name is not the isolation.** Two session guards in one Laravel application share a
session cookie and store by default, so a second guard does not produce a second session. What D-10
requires is that merchant users and operators are **separate identities behind separate providers**,
so neither can be resolved as the other on any guard. Whether the planned Filament panel additionally
gets its own session cookie, store, or domain belongs to that panel's contract, which does not exist
yet. Merchant resource traffic touches no session guard at all, per D-8.

### Why the refresh-rotation ceiling is accepted

Rotation bounds how long a captured refresh token stays useful, which is the property this design
needs, but it does not decide who wins. A captured token is usable until it is used, revoked, or
expires, and an attacker who rotates it first takes the successor while the legitimate client's copy
is already revoked. Passport cannot distinguish that from an ordinary concurrent-refresh race, so it
detects no family compromise.

Closing this would require tracking token lineage on Passport's refresh-token events - implementation
invented before a consumer exists, which is the objection ADR 0002 and ADR 0004 already applied
elsewhere. The ceiling is therefore accepted, described in full in the specification, and no part of
this system may claim protection stronger than rotation.

## Alternatives considered

- **Sanctum SPA cookie mode**, using `sanctum.stateful` domains and the `web` guard. Rejected: it
  requires the browser to call Laravel directly, reinstating sessions and CSRF on merchant resource
  routes, which contradicts D-7 and D-8.
- **Keep Sanctum tokens, held by the BFF.** Rejected: it preserves non-expiring unscoped tokens,
  offers no refresh, rotation, or standard revocation, and answers none of the login-boundary
  questions that made this issue necessary.
- **A public PKCE client.** Rejected: the BFF can hold a secret, so a public client discards defence
  in depth that costs nothing to keep.
- **The password grant.** Rejected: it moves merchant credentials through the BFF and removes the
  authorization-server login boundary this decision exists to define.
- **A server-side BFF session store with an opaque session cookie**, holding tokens outside the
  browser. Rejected because it contradicts D-4, but recorded deliberately: it is the escape hatch if
  the refresh-concurrency ceiling proves unacceptable in practice.
  Taken by [ADR 0007](0007-opaque-bff-session-custody.md), which reverses this rejection.

## Consequences

- **A merchant login page must exist before the authorization endpoint can work at all.** Passport
  redirects a guest to a `login` route this repository does not have.
- **Two authentication mechanisms must not coexist.** Sanctum tokens never expire, so retiring the
  package without revoking outstanding tokens would leave the superseded path open.
- **Choosing Passport imports its ceilings**: no refresh-token replay detection, no cascading family
  revocation, fleet-wide key rotation, and a refresh-concurrency race the grant does not resolve.
  These are stated as limitations in the specification, which is the only place they are described.
- **The decision is inert until ANT-29 and ANT-31 run.** This record changes no code, dependency,
  migration, or wire contract, and the specification's open items name what each task still owes.

## Related

- [ADR 0007](0007-opaque-bff-session-custody.md) - supersedes **D-4** and parts of **D-15**.
- [Merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md) -
  the canonical specification this ADR decides the mechanism for.
- [Interfaces and realtime](../architecture/interfaces-and-realtime.md) - surface boundaries and the
  deferred WebSocket contract.
- [Roadmap](../roadmap.md#merchant-dashboard-integration) - task state and ANT-29/30/31 sequencing.
- [Development environment](../specifications/foundation/development-environment.md#sanctum-as-actually-installed) -
  what Sanctum actually installed, and the publish-versus-load trap Passport repeats.
