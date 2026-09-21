# Merchant OAuth and BFF Authentication Specification

## Given

`ledgerflow-api` authenticated merchant requests with non-expiring, unscoped Sanctum personal access
tokens until ANT-29, which moved the resource API onto Passport's `api` guard and dropped the Sanctum
token table.
`ledgerflow-spa` is at pre-scaffold stage and will run Next.js with a browser-facing backend for
frontend.
`GET /api/payments/{paymentId}` already hides foreign and unknown identifiers behind one
byte-identical `404 payment_not_found`, and both payment routes already answer `401` as one shared
RFC 9457 problem document.

## Problem

A token in browser-readable storage is readable by any script that reaches the page, while sessions
on resource routes would reintroduce CSRF, cookie domains, and CORS onto a path that currently has
none of them.
The two repositories therefore need one settled contract covering the login boundary, token custody,
refresh behavior under concurrency, error semantics, and isolation from the future admin panel,
before either side is implemented.

## Decision

Passport is the OAuth 2.0 authorization and resource server.
The Next.js BFF is the only **browser-facing** OAuth client and the only holder of the tokens behind
a merchant browser session.
The browser holds cookies it cannot read, and never speaks to a Laravel resource endpoint.

The durable decisions are recorded in
[ADR 0006](../../adr/0006-merchant-oauth-bff-authentication.md) as **D-1** through **D-17**, and this
document is their canonical behavioral expansion.

**This document is implemented in `ledgerflow-api`.**
ANT-29 moved `/api/*` onto the `api` guard, set the lifetimes below, removed Sanctum and closed the
CORS open item; added the merchant login page, `GET /oauth/authorize` and `POST /oauth/token` with
mandatory `S256` PKCE, a one-shot `merchant_web` session, and consent skipped for the one configured
first-party client; and made refresh rotation a compare-and-set, added `POST /api/auth/logout`, and
scheduled `passport:purge`.
What remains open is owned by `ledgerflow-spa` or deferred behind the contracts below.
[The roadmap](../../roadmap.md) owns task state.

## Participants and trust boundaries

```text
browser --cookies--> Next.js BFF --Authorization: Bearer--> ledgerflow-api (resource server)
browser --top-level redirect-------------------------------> ledgerflow-api (authorization server)
```

- The browser holds only `HttpOnly` cookies written by the BFF, with the attributes fixed in the
  cookie contract below (**D-4**, **D-5**).
- The BFF is a confidential OAuth client and the sole custodian of browser-session tokens
  (**D-2**, **D-3**).
- The API exposes two independent surfaces: bearer-only resource routes (**D-6**, **D-8**) and
  session-backed authorization-server routes (**D-9**).
- The browser reaches the API only by top-level redirect to `/oauth/authorize` and by whatever the
  merchant login page needs; it never issues an API data request (**D-7**).

## Guard layout

Both guards are declared and in use as of ANT-29: the resource API runs on `api`, and merchants
authenticate on `merchant_web` through the login page below.

| Guard | Driver | Identity provider | Serves | Session |
|---|---|---|---|---|
| `api` | `passport` | Merchant users | `/api/*` merchant resource routes | None |
| `merchant_web` | `session` | Merchant users | `/oauth/authorize`, its approval routes, and the merchant login page | Yes |
| operator guard | `session` | Operators, a separate provider | Planned Filament panel, not installed | Yes |

`config('passport.guard')` must name `merchant_web`, not the default `web`, so the guard serving OAuth
authorization is named and reviewable rather than being whatever the application's default guard
happens to become.

**Merchant and operator identities are separate providers, and that separation is the isolation this
specification requires** (**D-10**).
A merchant user must not be resolvable as an operator, or an operator as a merchant, on any guard.

**Separate guards do not by themselves give separate sessions.**
Two session guards in one Laravel application share one session cookie and one session store by
default, keeping only their user keys apart, so naming a second guard is not an isolation mechanism.
Whether the planned Filament panel gets its own session cookie, store, or domain is owned by that
panel's future contract, not decided here.

`MerchantContext` must resolve the `api` guard, which is the single point at which the resource API
switches mechanism; ANT-29 moved that literal from `sanctum` to `api`.

The `merchant_web` session exists only to answer "which merchant user is authorizing this client".
It is never accepted on `/api/*`, and no merchant resource route carries session, cookie, or CSRF
middleware (**D-8**).

**The session is one-shot.** It is invalidated as soon as an authenticated merchant has attempted
one authorization, whether that attempt issued a code or was refused, so no merchant session
outlives the request that used it. A guest keeps its session, because that session carries the
intended URL the login page returns to. This is what makes logout observable without a
cross-site mutation on the API host: there is no lingering session for a later authorization request
to reuse silently, and the next one prompts for credentials again. It amends **D-15** step 3, which
assumed a session that survives authorization.

Only `GET /oauth/authorize` and `POST /oauth/token` are registered. Passport's remaining default
routes are not, in particular `POST /oauth/token/refresh`, which mints a `laravel_token` cookie that
the token guard would accept on `/api/*` and which would therefore contradict **D-8**.

## Client registration

- One confidential first-party client, created for the BFF (**D-3**).
  It is the only client in the browser flow.
  A separate Passport personal access client may be registered for local tooling such as the
  replacement for `make demo-token`; it issues no browser-session token, participates in no redirect,
  and is ANT-29's to define.
- The client secret is held by the BFF runtime and is owned operationally by `ledgerflow-infra`.
  It never enters this repository, the SPA repository, or a browser-visible environment variable.
- Redirect URIs are exact-match and enumerated per environment.
  No wildcard, no path-prefix matching.
- The consent screen is skipped for this client, which Passport does not do by default.
  Skipping consent is justified only while the client is first-party **and** its redirect URIs are
  exact-match; if either stops holding, consent must return.
  The skip is keyed to the one client id in `passport.bff_client_id`, not to first-party status in
  general, and it additionally requires that the client be ownerless and confidential.
  Passport's second route to skipping consent, reusing a prior grant when the user already holds an
  active token for the client, is disabled: with no consent screen, a token can only come from a
  client already allowed to skip, and reuse would extend that allowance to any client that ever held
  one.
  No consent screen exists, so any other client is refused rather than prompted.
- No scopes are defined, so no scope is requested and none is granted (**D-14**).

## Sequences

### 1. Login

A BFF route that requires a merchant, reached with no usable token, begins an authorization request.
The BFF never renders a login form and never collects merchant credentials; the authorization server
owns that boundary.

### 2. Authorization redirect

The BFF generates a PKCE `code_verifier` and its `S256` `code_challenge`, and a random `state`.
It stores the verifier and the state server-side of the browser boundary, in a short-lived
`HttpOnly` cookie it owns, and redirects the browser to `/oauth/authorize` with `client_id`,
`redirect_uri`, `response_type=code`, `code_challenge`, `code_challenge_method=S256`, and `state`.

The authorization server resolves the merchant on the `merchant_web` guard.
A guest is sent to the merchant login page and returns to the same authorization request afterwards.
Consent is skipped for this first-party client, so an authenticated merchant is redirected straight
back with a code, and the `merchant_web` session is invalidated in the same response.

**PKCE is enforced by the authorization server, not only by the BFF.** An authorization request
without a `code_challenge`, or with any `code_challenge_method` other than `S256`, is refused with
`400 invalid_request` before the request reaches the grant. League itself requires PKCE only from
public clients and treats a missing method as `plain`, so without this the BFF would be the sole
enforcer of **D-3**.

### 3. Callback

The BFF receives `code` and `state` on its exact-match redirect URI.
It compares `state` against the value in its own cookie and rejects any mismatch or absence, then
deletes that cookie whether validation passed or failed.
A callback that fails `state` validation never reaches the token endpoint.

### 4. Token exchange

The BFF posts to `/oauth/token` with `grant_type=authorization_code`, the `code`, the
`redirect_uri`, `client_id`, `client_secret`, and `code_verifier`.
It writes the returned access and refresh tokens into its two cookies and redirects the browser to
the originally requested page.

The token endpoint carries neither session nor CSRF middleware, because the BFF calls it server to
server. It is throttled by client: 600 requests a minute for the configured BFF client, and 60 a
minute by address for anything else. A single budget keyed by address would otherwise put every
merchant behind the BFF's one egress address into the same bucket.
The address fallback is only as granular as the deployment makes the client address: nothing in this
repository configures trusted proxies or a real-IP header, so behind a proxy every non-BFF caller
shares one bucket. That is the intended floor here, because the BFF is the only registered client.
Neither token is ever placed in a response body, a URL, a template, or a browser-readable store
(**D-5**).

### 5. Authenticated request

The browser calls a BFF route and its cookies travel automatically.
The BFF reads the access-token cookie and calls the Laravel resource endpoint with
`Authorization: Bearer` (**D-6**).
It forwards the API's status, problem document, and `Retry-After` to the browser unchanged, so the
`202 projection_pending` and `503 projection_rebuilding` contracts reach the dashboard intact.

### 6. Refresh

A `401` from the resource API, and only a `401`, may trigger a refresh (**D-12**).
The BFF posts to `/oauth/token` with `grant_type=refresh_token`, the refresh token, `client_id`, and
`client_secret`.
Passport rotates: the presented refresh token and its access token are revoked, and a new pair is
returned.
The BFF overwrites both cookies with the new pair in the same response.

**Rotation is a compare-and-set.** The authorization server revokes the presented refresh token with
one conditional update that moves the row only while it is still unrevoked, and a request that moves
no row is refused with `invalid_grant` before any successor is issued.
Passport's own repository revokes unconditionally after a separate check, so two concurrent refreshes
carrying the same token could both pass that check and both receive a live pair, forking two families.
The conditional update is what makes "the loser receives `invalid_grant`" true rather than likely.

A failed refresh is terminal for the request.
The BFF clears both cookies and drives the browser back through the authorization redirect; it does
not retry the refresh.

### 7. Retry

After a successful refresh the BFF replays the **original** request exactly once, with the new
access token (**D-12**).
A second `401` is returned to the browser as an authentication failure.
There is no retry loop and no second refresh within one browser request.

### 8. Logout

Logout performs three actions, and a logout that performs fewer is incomplete (**D-15**):

1. revoke the active access token and its refresh token on the authorization server;
2. delete both BFF cookies with the same attributes they were written with;
3. end the `merchant_web` authorization session.

Ending the authorization session is what makes logout observable.
Without it, the next authorization redirect re-issues tokens silently and the merchant is never
actually logged out.

**Step 1 is `POST /api/auth/logout`**, on the `api` guard like every other resource route.
It revokes the access token the request carries and the refresh token issued beside it in one
transaction, and answers `204` with no body, echoing neither token.
It is documented in [the wire contract](../../api/openapi.json).

**Step 3 needs no call.** The `merchant_web` session is invalidated as soon as an authenticated
merchant has attempted one authorization, so by the time a merchant is holding tokens there is no
session left to end, and the next authorization prompts for credentials again.

**The endpoint is not idempotent, and does not claim to be.** A second call with the same, now
revoked, token is answered `401 unauthenticated`, exactly as any other resource route answers it.
Step 2 and whatever the browser should see across a repeated or interrupted logout are the BFF's, and
belong to ANT-31.

### 9. Revocation

A token expires on its own at the lifetime above, because Passport carries expiry inside the signed
token.
Revocation is the only way to invalidate one **before** that point: the `expires_at` columns are
display-only, so editing them changes nothing.
Pruning revoked and expired rows is bookkeeping, not a security control.
`passport:purge` is scheduled daily beside the relay schedule for exactly that reason: validation
reads `revoked` from the database on every request, so a row that outlives a missed run is already
refused, and no authentication decision depends on the purge having run.
Password-change revocation is deferred (**D-16**).

## Cookie contract

Both token cookies are written, read, and deleted only by the BFF (**D-4**).

| Property | Access-token cookie | Refresh-token cookie |
|---|---|---|
| Purpose | Bearer credential for resource calls | Credential for rotation only |
| `HttpOnly` | Yes | Yes |
| `Secure` | Yes, always in production | Yes, always in production |
| `SameSite` | `Lax` | `Lax` |
| Path | `/` | `/` |
| Domain | Host-only; no `Domain` attribute | Host-only; no `Domain` attribute |
| Lifetime | Session cookie, bounded by the 15-minute token | Bounded by the 14-day refresh token |
| Deletion | Cleared on logout and on terminal refresh failure | Cleared on logout and on terminal refresh failure |

- `SameSite=Lax`, not `Strict`.
  Under both the local origins and the required production shape the OAuth callback is a same-site
  navigation, which `Strict` would also allow, so the callback is not what rules it out.
  `Strict` is rejected because it additionally withholds cookies on any inbound link from another
  site, so a merchant following a link into the dashboard would land logged out and pay an extra
  authorization round trip.
  `Lax` also stays correct if the SPA ever moves to a different registrable domain, where the
  callback would become cross-site and `Strict` would drop the cookies on return.
  Because `Lax` still sends cookies on top-level `GET` navigation, **no BFF route may mutate state
  on `GET`**.
- **No `Domain` attribute is ever set.**
  In production that is what keeps the BFF's tokens off the API host; locally it cannot achieve the
  same thing, for the reasons set out under [Origins](#origins).
- **Both cookies use `Path=/`.**
  The refresh cookie cannot be scoped to the refresh endpoint alone: a server-rendered page or any
  ordinary BFF request that receives a `401` must be able to recover in place, and it can only do
  that if the refresh cookie arrived with that request.
  A narrower path would make recovery depend on which URL the browser happened to be on.
  The refresh credential's exposure is therefore bounded by `HttpOnly`, `Secure`, `SameSite`, and BFF
  encryption rather than by path.
- Cookie values are encrypted by the BFF with a BFF-owned key, so `HttpOnly` is not the only thing
  standing between a leaked cookie jar and a live token.
- Each cookie must stay under the 4096-byte per-cookie limit.
  A Passport access token is a JWT and encryption inflates the encoded value, so the final sizes are
  measured in ANT-31, not assumed.
- A third short-lived `HttpOnly` cookie carries the PKCE verifier and `state` between the redirect
  and the callback, and is deleted at the callback.

## Origins

| Environment | SPA and BFF | API |
|---|---|---|
| Local development | `http://localhost:3000` | `http://localhost:8080` |
| Production | A dedicated host, such as `app.example.com` | A separate host, such as `api.example.com` |

The production hostnames themselves are an open item below; the **shape** is not, and the two must be
distinct hosts.

### What the local origins mean

Three different browser concepts are involved, and conflating them is how cookie bugs get shipped.

- **They are different origins.** An origin is scheme, host, and port, so `http://localhost:3000` and
  `http://localhost:8080` are distinct for CORS, `window.origin`, and storage partitioning.
- **They are the same site for `SameSite` processing.** A site is the registrable domain plus scheme
  and ignores the port, so a redirect between the two is a same-site navigation.
- **Cookies are scoped by host and path, never by port.** The cookie protocol has no concept of port.

Those three together produce one consequence that must be stated rather than glossed over: a
host-only cookie on `localhost` with `Path=/` **is sent to both local services**. The BFF's token
cookies therefore reach the local API as well as the BFF.

**This is an accepted local-development exception, not cookie isolation, and must never be described
as isolation.** It is acceptable only because both local services are trusted, the BFF's cookie names
are distinct from anything the API sets, and each service ignores cookies it does not own. The API
authenticates from `Authorization: Bearer` alone (**D-6**, **D-8**), so a cookie arriving at it is
inert.

`127.0.0.1` is deliberately not used alongside `localhost`: it is a different cookie host, and mixing
the two silently splits the cookie jar. ANT-29 set `APP_URL` to `http://localhost:8080`; the port is
still published on the `127.0.0.1` loopback interface, because the bind address and the origin are
separate concerns.

### What production requires

Distinct hosts, and cookies that stay **host-only with no `Domain` attribute**. A `Domain` attribute
would widen a cookie to the parent domain and hand the BFF's tokens to the API host on every request;
omitting it keeps them on the BFF host, which is the isolation the local environment cannot provide.

**Production cookies are always `Secure`.** If an implementation cannot set `Secure` over plain HTTP
in local development, it may drop that one attribute for `localhost` only, and must record the
exception where the cookies are written. No other attribute is relaxed anywhere, and nothing about
production depends on that exception.

## Token lifetimes

| Token | Lifetime | Rotation |
|---|---|---|
| Access | 15 minutes | Replaced on refresh |
| Refresh | 14 days | Rotated on every use |

Both are Passport configuration (**D-11**).
A 15-minute access token keeps the refresh path exercised often enough that its failure modes are
visible in normal use rather than only at the 14-day boundary.

## Status code behavior

**`401` is the only status that may trigger a refresh (D-12), and every other status is surfaced to
the browser unchanged (D-13).** The payments specifications own what each one means.

| Status | BFF action |
|---|---|
| `401 unauthenticated` | Refresh once, replay the original request once |
| `403` | Never refresh. Not produced today: no scopes are defined (**D-14**) |
| `404 payment_not_found` | Never refresh. Surface as not found |
| `409 idempotency_payload_mismatch` | Never refresh. Surface the problem document |
| `422 validation_failed` | Never refresh. Surface the problem document |
| `503 projection_rebuilding`, `503 idempotency_contention` | Never refresh. Honour `Retry-After` |

Refreshing on anything but `401` would turn an authorization, tenancy, validation, or availability
answer into a credential problem and rotate tokens for no reason.
The tenant-hiding `404` matters most: it is what a merchant receives for another merchant's payment,
and treating it as a credential failure would both act on that distinction and churn tokens.

The BFF cannot tell an expired token from a revoked one, because both arrive as the same `401`
problem document.
It does not need to: one refresh attempt resolves the first case and fails cleanly in the second.

## Browser-to-BFF CSRF and origin validation

Cookies are the credential for BFF routes, so every state-changing BFF route defends itself:

- `SameSite=Lax` blocks cross-site cookie-bearing `POST`.
- Every mutating route validates `Origin`, or `Sec-Fetch-Site` where present, against its own
  origin.
- No mutating route is reachable by `GET`.

Laravel's CSRF middleware is irrelevant to this boundary.
Merchant resource routes carry no session and no cookie credential (**D-8**), so there is nothing
for a cross-site request to ride.
The BFF-to-API hop is server-to-server and depends on no browser CORS behavior at all (**D-6**).

## Secret hygiene

No access token, refresh token, authorization code, PKCE verifier, `state`, client secret, password,
or cookie value may appear in logs, error responses, URLs rendered to a user, source control, or
browser-readable storage.

Two paths need specific care:

- The BFF callback URL carries `code` and `state` in its query string, so it lands in any access log
  that records query strings.
  Codes are single-use and short-lived, and PKCE binds them to the verifier, but the callback path
  must be excluded from query-string logging.
- The merchant login page handles credentials on the API domain and must not log request bodies.

This extends the existing repository rule that logs and errors must not expose raw idempotency keys
or sensitive payment payloads.

## Limitations and non-claims

- **No refresh-token replay detection, and no cascading family revocation.**
  Rotation revokes the presented token and issues a successor, but nothing propagates across a token
  family.
  A captured refresh token stays usable until it is used, revoked, or expires.
  **If the attacker uses it before the legitimate client does, the attacker receives the successor
  token and the legitimate client's copy is already revoked**, so the legitimate client loses refresh
  access and is forced back through authorization while the attacker keeps rotating.
  Passport cannot tell that case apart from an ordinary concurrent-refresh race, so it does not
  detect the family compromise and nothing is revoked in response to it.
  This specification claims rotation, and nothing stronger.
- **Concurrent refresh is arbitrated, not avoided.**
  The compare-and-set above decides the race: exactly one of two concurrent requests carrying the
  same refresh token receives a successor pair, and the loser receives `invalid_grant`.
  It does not spare the loser that answer.
  The browser cookie jar is still the authoritative store, so a BFF that lets two requests refresh at
  once will still see one of them fail and have to recover.
  Single-flight coordination across Next.js processes remains an ANT-31 obligation.
- **Key rotation is fleet-wide.**
  Passport signs access tokens with one key pair and offers no `kid` rollover, so rotating keys
  invalidates every outstanding access token at once.
- **No `403` exists on the merchant API.**
  `ProblemType` has no `Forbidden` case and `docs/api/openapi.json` documents no `403`.
  The row above defines BFF behavior should one ever be introduced; it does not assert one exists.

## Deferred

- **WebSocket authentication** (**D-16**).
  This is a contract, not an omission: a browser WebSocket handshake cannot carry an `Authorization`
  header, so a Reverb channel-authorization endpoint would have to be proxied through the BFF, which
  is a design this milestone does not need.
  Reverb is not installed and `BROADCAST_CONNECTION` is `log`.
  See [interfaces and realtime](../../architecture/interfaces-and-realtime.md#websocket-boundary).
- **Password-change token revocation** (**D-16**).
  There is no password-change flow in this repository, so the hook has nothing to attach to yet.

## Open items owned by the implementation tasks

Owned by **ANT-29** in this repository:

- ~~the merchant login page, its throttling, and its session lifetime on the `merchant_web` guard~~ —
  done in ANT-29: a login page on the API host, named `login`, throttled to 5 attempts a minute by
  normalized email and address together, with the session regenerated on success and invalidated as
  soon as it has served one authorization;
- ~~whether client secrets are hashed, which makes them unrecoverable after creation~~ — done in
  ANT-29: Passport 13 hashes them unconditionally, so `make demo-bff-client` prints the secret once
  and rotation means revoking the client and registering a new one;
- ~~key storage and rotation ownership, and whether rotation also invalidates refresh tokens~~ — done
  in ANT-29: keys are generated locally and supplied from the environment in production, rotating the
  signing pair invalidates access tokens only, and rotating `APP_KEY` invalidates refresh tokens and
  pending codes; see
  [development environment](../foundation/development-environment.md#passport-as-actually-installed);
- ~~the `passport:purge` schedule alongside the existing relay schedule~~ — done in ANT-29: scheduled
  daily, and bookkeeping only;
- ~~revocation of existing Sanctum tokens, and the replacement for `make demo-token`~~ — done in
  ANT-29: dropping `personal_access_tokens` revoked every Sanctum token, and `make demo-token` now
  prints a one-hour Passport personal access token issued through a local-only personal access
  client;
- ~~the currently unreviewed framework CORS default~~ — done in ANT-29: `config/cors.php` declares no
  paths, so no browser origin receives a CORS grant.

Owned by **ANT-31** in `ledgerflow-spa`:

- the single-flight refresh mechanism and whether it accepts a short server-side overlap window;
- deleting both token cookies on logout and on terminal refresh failure, with the attributes they
  were written with;
- what the browser sees across a repeated or interrupted logout, since the API endpoint is not
  idempotent and answers a second call with the same token `401 unauthenticated`;
- final cookie names, including whether the `__Host-` prefix is used in production;
- measured cookie sizes against the 4096-byte limit.

Owned jointly, before either task starts:

- the actual production hostnames for the BFF and the API. The shape is fixed above: two distinct
  hosts, host-only cookies, no `Domain` attribute. Local origins are fixed above too.

## Related

- [ADR 0006](../../adr/0006-merchant-oauth-bff-authentication.md) - the decision record, its
  alternatives, and its consequences.
- [Interfaces and realtime](../../architecture/interfaces-and-realtime.md) - surface ownership and
  the WebSocket boundary.
- [Payments projection and query](../payments/projection-and-query.md) - the merchant-scoped read
  behavior behind the tenant-hiding `404`.
- [Payments initiation and idempotency](../payments/initiation-and-idempotency.md) - the write
  contract the BFF forwards unchanged.
- [Development environment](../foundation/development-environment.md) - what is actually installed
  today, including Passport and how it is wired.
