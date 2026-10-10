# Merchant OAuth and BFF Authentication Specification

## Given

`ledgerflow-api` authenticated merchant requests with non-expiring, unscoped Sanctum personal access
tokens until ANT-29, which moved the resource API onto Passport's `api` guard and dropped the Sanctum
token table.
`ledgerflow-spa` runs Next.js with a browser-facing backend for frontend.
`GET /api/payments/{paymentId}` already hides foreign and unknown identifiers behind one
byte-identical `404 payment_not_found`, and both payment routes already answer `401` as one shared
RFC 9457 problem document.

## Problem

A token in browser-readable storage is readable by any script that reaches the page, while sessions
on resource routes would reintroduce CSRF, cookie domains, and CORS onto a path that currently has
none of them.
Tokens held in cookies, even `HttpOnly` ones, leave the browser cookie jar as the only token store, so
a fleet of BFF processes cannot coordinate refresh and a server render cannot persist a rotated pair.
The two repositories therefore need one settled contract covering the login boundary, token custody,
refresh behavior under concurrency, logout under dependency failure, error semantics, and isolation
from the future admin panel.

## Decision

Passport is the OAuth 2.0 authorization and resource server.
The Next.js BFF is the only **browser-facing** OAuth client.
It holds the tokens behind a merchant browser session **only in a server-side session record in
Redis**, and the browser holds one opaque identifier it cannot read and that carries no token.
The browser never speaks to a Laravel resource endpoint.

The durable decisions are recorded in two ADRs:

- [ADR 0006](../../adr/0006-merchant-oauth-bff-authentication.md) as **D-1** through **D-17**;
- [ADR 0007](../../adr/0007-opaque-bff-session-custody.md) as **S-1** through **S-12**, which
  supersede **D-4** in full and **D-15** step 2 with its unconditional revocation claim.

This document is their canonical behavioral expansion.

**The authorization and resource server half is implemented in `ledgerflow-api`.**
ANT-29 moved `/api/*` onto the `api` guard, set the lifetimes below, removed Sanctum and closed the
CORS open item; added the merchant login page, `GET /oauth/authorize` and `POST /oauth/token` with
mandatory `S256` PKCE, a one-shot `merchant_web` session, and consent skipped for the one configured
first-party client; and made refresh rotation a compare-and-set, added `POST /api/auth/logout`, and
scheduled `passport:purge`.
**The BFF half is specified and not implemented.**
ANT-31 implements it in `ledgerflow-spa`; the deployed session store is a `ledgerflow-infra` task.
[The roadmap](../../roadmap.md) owns task state.

## Participants and trust boundaries

```text
browser --session cookie--> Next.js BFF --Authorization: Bearer--> ledgerflow-api (resource server)
                                 |
                                 +--> Redis (BFF session and OAuth transaction records)
browser --top-level redirect----------------------------------------> ledgerflow-api (authorization server)
```

- The browser holds only the two `HttpOnly` cookies fixed in the cookie contract below, each carrying
  one opaque identifier and nothing else (**S-3**, **S-5**, **D-5**).
- The BFF is a confidential OAuth client and the sole custodian of browser-session tokens
  (**D-2**, **D-3**, **S-1**).
- Redis holds the BFF's session and OAuth transaction records.
  `ledgerflow-spa` owns the record schema, TTLs, key namespace, refresh coordination, and client
  code; `ledgerflow-infra` owns the deployed store; `ledgerflow-api` owns none of this state and never
  reads it (**S-2**).
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
Tenant scoping is resolved there on every request, so the BFF needs no merchant identity to authorize
anything and `ledgerflow-api` exposes no `GET /api/auth/me` (**S-12**).

The `merchant_web` session exists only to answer "which merchant user is authorizing this client".
It is never accepted on `/api/*`, and no merchant resource route carries session, cookie, or CSRF
middleware (**D-8**).
It is unrelated to the BFF session: the two live on different hosts, in different stores, and neither
is ever accepted in place of the other.

**The `merchant_web` session is one-shot.** It is invalidated as soon as an authenticated merchant
has attempted one authorization, whether that attempt issued a code or was refused, so no merchant
session outlives the request that used it. A guest keeps its session, because that session carries
the intended URL the login page returns to. This is what makes logout observable without a
cross-site mutation on the API host: there is no lingering session for a later authorization request
to reuse silently, and the next one prompts for credentials again. It amends **D-15** step 3, which
assumed a session that survives authorization.

Only `GET /oauth/authorize` and `POST /oauth/token` are registered. Passport's remaining default
routes are not, in particular `POST /oauth/token/refresh`, which mints a `laravel_token` cookie that
the token guard would accept on `/api/*` and which would therefore contradict **D-8**.

## Client registration

- One confidential first-party client, created for the BFF (**D-3**).
  It is the only client in the browser flow.
  A separate Passport personal access client exists only in the local and testing environments for
  `make demo-token`; it issues no browser-session token and participates in no redirect.
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

## BFF state in Redis

Two record kinds exist, both owned by `ledgerflow-spa` (**S-2**).
Their exact field names, encoding, and key derivation are the SPA's record schema and are
deliberately not fixed here; the requirements below are.

### Session record

Keyed by the opaque session identifier, and created only at a successful callback.

- Holds the current access token, the current refresh token, a **version** that every successful
  write advances, and a **lifecycle state** that is either active or terminated.
- Expires by a fixed, non-sliding TTL of 14 days from creation, matching the session cookie's
  `Max-Age`.
  Refresh never extends it, so a session lasts at most 14 days whatever its refresh activity.
- Is written only by compare-and-set against the version the writer read: a write whose expected
  version no longer matches, or whose record is terminated or absent, changes nothing (**S-7**).
- **A terminated record is never made active again** (**S-8**).
  Termination is itself a versioned write, so any refresh that read the record before it fails its
  own compare-and-set afterwards.

### OAuth transaction record

Keyed by the opaque transaction identifier, and created when an authorization request begins.

- Holds the PKCE `code_verifier`, the `state`, a safe return path, and the transaction's expiry
  (**S-4**).
- Lives initially 10 minutes, matching the OAuth cookie.
- Is single-use: the callback consumes it atomically, so a replayed callback finds nothing and fails.
- The return path is a same-origin relative path chosen by the BFF, never an absolute URL taken from
  the request, so the callback cannot be turned into an open redirect.

### Identifiers are bearer secrets

A session identifier, or a transaction identifier, is as good as the credential behind it for as
long as its record lives (**S-3**).

- Each is generated from a cryptographically secure random source with enough entropy to be
  unguessable, and is never derived from merchant, user, or time data.
- Neither is ever logged, placed in an error, a URL, a template, or a response body, or sent
  anywhere except in its own cookie.
- Redis tooling that prints key names, such as `SLOWLOG`, `MONITOR`, and keyspace listings, counts as
  exposure, so a key must not contain an identifier in recoverable form; how keys are derived belongs
  to the record schema.
- Cookie values are not encrypted.
  They carry only an opaque identifier, and encrypting it would not make it any less a bearer secret.

## Sequences

### 1. Login

A BFF route that requires a merchant, reached with no live session record, begins an authorization
request.
"Authenticated" means "the session cookie names an active session record"; the BFF never decodes the
access token to decide it (**S-12**).
The BFF never renders a login form and never collects merchant credentials; the authorization server
owns that boundary.

### 2. Authorization redirect

The BFF generates a PKCE `code_verifier` and its `S256` `code_challenge`, a random `state`, and a new
transaction identifier.
It writes the OAuth transaction record, sets `__Host-ledgerflow_oauth` to the transaction identifier,
and redirects the browser to `/oauth/authorize` with `client_id`, `redirect_uri`,
`response_type=code`, `code_challenge`, `code_challenge_method=S256`, and `state`.
If the transaction record cannot be written, no redirect is issued and the request fails closed.

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

1. It reads the transaction identifier from `__Host-ledgerflow_oauth` and consumes the transaction
   record in one atomic step.
2. It rejects the callback if the cookie is absent, the record is absent or expired, or `state` does
   not equal the stored value.
3. It clears `__Host-ledgerflow_oauth` on **every** outcome, successful or not.

A callback that fails any check never reaches the token endpoint.
Binding `state` to a server-side record named by a cookie the attacker cannot set is also what
prevents login CSRF: a callback forged into a victim's browser finds no matching transaction.

### 4. Token exchange

The BFF posts to `/oauth/token` with `grant_type=authorization_code`, the `code`, the
`redirect_uri`, `client_id`, `client_secret`, and the stored `code_verifier`.
It creates a new session record with the returned pair, sets `__Host-ledgerflow_session` to a new
session identifier, and redirects the browser to the stored return path.
A new identifier is issued on every login and never reused, so an identifier planted before login
cannot become an authenticated session.
If the session record cannot be written, no session cookie is set, the tokens are discarded, and the
request fails closed.

The token endpoint carries neither session nor CSRF middleware, because the BFF calls it server to
server. It is throttled by client: 600 requests a minute for the configured BFF client, and 60 a
minute by address for anything else. A single budget keyed by address would otherwise put every
merchant behind the BFF's one egress address into the same bucket.
The address fallback is only as granular as the deployment makes the client address: nothing in this
repository configures trusted proxies or a real-IP header, so behind a proxy every non-BFF caller
shares one bucket. That is the intended floor here, because the BFF is the only registered client.
Neither token is ever placed in a cookie, a response body, a URL, a template, or a browser-readable
store (**D-5**, **S-1**).

### 5. Authenticated request

The browser calls a BFF route, or requests a server-rendered page, and its session cookie travels
automatically.
The BFF reads the session record and calls the Laravel resource endpoint with the stored access
token as `Authorization: Bearer` (**D-6**).
It forwards the API's status, problem document, and `Retry-After` to the browser unchanged, so the
`202 projection_pending` and `503 projection_rebuilding` contracts reach the dashboard intact.

### 6. Refresh

A `401` from the resource API, and only a `401`, may trigger a refresh (**D-12**).
Refresh runs wherever that `401` is observed, including inside a server render, and writes **only**
to the session record; it never writes a cookie, because the session identifier does not change on
rotation (**S-6**).

**Rotation is a compare-and-set on the authorization server.** It revokes the presented refresh token
with one conditional update that moves the row only while it is still unrevoked, and a request that
moves no row is refused with `invalid_grant` before any successor is issued.
Passport's own repository revokes unconditionally after a separate check, so two concurrent refreshes
carrying the same token could both pass that check and both receive a live pair, forking two families.
The conditional update is what makes "the loser receives `invalid_grant`" true rather than likely.

The BFF refresh is correct because of two compare-and-sets, and **coordinated** by a lock (**S-7**):

1. **Coordinate.** The request that saw the `401` tries to take a per-session Redis lock with
   `SET NX PX`.
   The lock keeps losers away from Passport; it is not what makes the outcome correct, because it
   expires by wall clock and a stalled holder can outlive it.
2. **Winner.** The lock holder re-reads the record.
   If the version already moved past the one that produced the `401`, it uses the stored pair and
   does not call Passport.
   Otherwise it posts to `/oauth/token` with `grant_type=refresh_token`, the stored refresh token,
   `client_id`, and `client_secret`, and writes the successor pair by compare-and-set against the
   version it read.
3. **Loser.** A request that cannot take the lock waits, for a bounded time, for the record version
   to move, then re-reads and uses the new pair.
   A loser whose wait expires without a new version fails that request as retryable.
   It does not call Passport, and it never terminates or deletes the session on a timeout.
4. **Re-read after `invalid_grant`.** A refresh answered `invalid_grant` re-reads the record before
   concluding anything.
   A newer active version means another writer rotated first, and the request uses that pair.
   An unchanged version means the stored refresh token is dead, which is terminal.
5. **Lost compare-and-set.** A winner whose write fails because the version moved uses the newer pair
   if the record is active.
   If the record is terminated or absent, the session was logged out while the refresh was in flight:
   the winner discards its successor pair, attempts `POST /api/auth/logout` with the discarded access
   token as best effort, and treats the request as unauthenticated.
   A refresh can never restore a logged-out session (**S-8**).

**Terminal refresh failure** terminates and deletes the session record and sends the browser back
through the authorization redirect; it does not retry the refresh.
A Route Handler also clears `__Host-ledgerflow_session`.
A server render cannot write cookies, so it deletes the record and issues a redirect instead; the
stale identifier left in the browser is inert because no record stands behind it, and the next
successful callback replaces it.

**A crash after rotation loses the session; it does not heal it.**
If the BFF crashes, or Redis refuses the write, after Passport rotated the pair but before the
session record holds the successor, the record keeps a refresh token Passport has already revoked.
The next refresh receives `invalid_grant`, the re-read finds no newer version, and the session ends
with a re-authorization.
The successor pair is held by no participant and expires at its own lifetime.
This is safe, because nothing is granted or exposed, but the merchant's session is lost, not
recovered.

### 7. Retry

After a successful refresh, or after adopting a pair another writer stored, the BFF replays the
**original** request exactly once, with the current access token (**D-12**).
A second `401` is returned to the browser as an authentication failure.
There is no retry loop and no second refresh within one browser request.

### 8. Logout

Logout is `POST` only, validated as a mutating route under
[Browser-to-BFF CSRF and origin validation](#browser-to-bff-csrf-and-origin-validation); `GET`
answers `405` (**S-9**).

1. **Terminate** the session record by compare-and-set, so any refresh already in flight can no
   longer write into it.
   If a refresh wins the race first, logout re-reads and terminates the newer version, so the pair it
   revokes next is the current one.
2. **Revoke** by calling `POST /api/auth/logout` with the stored access token, as best effort.
3. **Delete** the session record.
4. **Clear** `__Host-ledgerflow_session`, **always**, whatever happened in steps 1 to 3.

`POST /api/auth/logout` is on the `api` guard like every other resource route.
It revokes the access token the request carries and the refresh token issued beside it in one
transaction, and answers `204` with no body, echoing neither token.
It is documented in [the wire contract](../../api/openapi.json).
**The endpoint is not idempotent, and does not claim to be**: a second call with the same, now
revoked, token is answered `401 unauthenticated`, exactly as any other resource route answers it.

**The browser sees the same logout whatever the API answers.** `204`, `401`, a `5xx`, and a timeout
from step 2 all end with the record deleted and the cookie cleared; a `401` means the token was
already dead.
A repeated logout finds no record, revokes nothing, and still clears the cookie.

**The authorization session needs no step.** The `merchant_web` session is invalidated as soon as an
authenticated merchant has attempted one authorization, so by the time a merchant holds a BFF session
there is no authorization session left to end, and the next authorization prompts for credentials
again.

**Under dependency failure, logout clears the browser but guarantees nothing else.**

| Failure | What still happens | What is not guaranteed |
|---|---|---|
| Passport unreachable or failing | Record terminated and deleted, cookie cleared | The tokens stay valid at Passport until they expire. No participant holds them any longer, unless they leaked beforehand |
| Redis unavailable | Cookie cleared | The record is neither terminated nor deleted, and the tokens are not revoked, because the BFF cannot read them. **A copied session identifier regains the session once Redis recovers, until the record's TTL.** |
| Interrupted after termination, before the cookie is cleared | The record is terminated or gone, so the stale cookie is inert | Revocation may not have happened; the tokens are then held by no participant and expire at their lifetimes |

This specification does not claim that logout revokes a copied session identifier.
It claims that the browser is logged out, and that revocation happens whenever Redis and Passport
both answer.

### 9. Revocation

A token expires on its own at the lifetime below, because Passport carries expiry inside the signed
token.
Revocation is the only way to invalidate one **before** that point: the `expires_at` columns are
display-only, so editing them changes nothing.
Pruning revoked and expired rows is bookkeeping, not a security control.
`passport:purge` is scheduled daily beside the relay schedule for exactly that reason: validation
reads `revoked` from the database on every request, so a row that outlives a missed run is already
refused, and no authentication decision depends on the purge having run.
Password-change revocation is deferred (**D-16**).

## Redis availability and durability

**The BFF fails closed** (**S-10**).
A request whose session record cannot be read is not authenticated, and it is not treated as a guest
either: it fails observably, without starting an authorization request the BFF could not record.
No cached, in-process, or cookie-borne fallback exists, and none may be added.
Every Redis failure is observable in BFF telemetry, which never includes an identifier or a token.

**Loss of the store forces re-authorization and never grants access.**
A failover, flush, or restart that loses records leaves every affected browser holding an inert
identifier, and the next request begins a new authorization.

**The store must survive BFF process replacement.**
That is why it is shared and external: an in-process store would log every merchant out on every
deploy.
How Redis persistence and replication are configured is `ledgerflow-infra`'s.

**Contract for the deployed store** (**S-11**):

- **Database 0 only, with cluster-compatible operations.**
  No numbered database and no `SELECT`, because ElastiCache cluster mode has neither; every
  multi-key operation stays within one hash slot.
- **A dedicated key namespace and separate ACL credentials** for the BFF, which cannot read or write
  the API's keys, and which the API cannot use.
- **TLS in every deployed environment.**
- **`noeviction`, with capacity monitoring and alerting.**
  An evicted live session is a silent logout with no trace in the application; under `noeviction`,
  memory pressure surfaces instead as refused writes, which the BFF fails closed on and reports.
  Namespace and ACL isolation do not protect sessions from shared memory pressure, so this is a
  separate requirement.
- Whether the BFF shares the planned API replication group or gets a dedicated one is
  `ledgerflow-infra`'s decision, provided the requirements above hold.

**Local development** uses a Redis of the same engine version, on database 0, under the same key
namespace contract.
The deployed store blocks deployment, not local development.

## Cookie contract

Both cookies are written, read, and deleted only by the BFF, through **one canonical module** that
owns their names and attributes for every write and every deletion (**S-5**).
A browser replaces or removes a cookie by name, host, and path, but reusing the full attribute set
from one place is what keeps a deletion from silently missing its cookie.

| Property | `__Host-ledgerflow_session` | `__Host-ledgerflow_oauth` |
|---|---|---|
| Value | Opaque session identifier only | Opaque transaction identifier only |
| `HttpOnly` | Yes | Yes |
| `Secure` | Yes, in every environment | Yes, in every environment |
| `SameSite` | `Lax` | `Lax` |
| Path | `/` | `/` |
| Domain | None; host-only | None; host-only |
| Lifetime | Fixed `Max-Age` of 14 days, non-sliding, matching the record TTL | Fixed, initially 10 minutes, matching the record TTL |
| Written | At a successful callback | When an authorization request begins |
| Cleared | At logout, and by a Route Handler on terminal refresh failure | At every callback outcome |

- **The `__Host-` prefix makes the browser enforce the attributes this contract requires**: `Secure`,
  `Path=/`, and no `Domain`.
  A cookie that violates any of them is rejected by the browser rather than silently widened.
- **No name, attribute, or `Secure` behavior branches by environment.**
  Local development relies on browsers that treat `http://localhost` as a secure context and accept
  `Secure` `__Host-` cookies there.
  ANT-31 validation includes an explicit browser compatibility check.
  If a required browser refuses them, local HTTPS is the correction; environment-dependent cookie
  names or a dropped `Secure` attribute are not.
- `SameSite=Lax`, not `Strict`.
  Both cookies must arrive on the top-level `GET` navigation that returns from the authorization
  server, which `Lax` allows even if the SPA and API ever sit on different registrable domains.
  `Strict` would additionally withhold the session cookie on any inbound link from another site, so a
  merchant following a link into the dashboard would land logged out.
  Because `Lax` still sends cookies on top-level `GET` navigation, **no BFF route may mutate state on
  `GET`**, except the two OAuth navigation endpoints below.
- **Both cookies use `Path=/`**, which the `__Host-` prefix requires, and which lets any page, server
  render, or BFF route recover in place.
- The cookie is written exactly at the callback and cleared at logout; refresh never touches it.
  That is what lets refresh run inside a server render, which cannot write cookies (**S-6**).
- Each cookie carries a fixed-length identifier, so the 4096-byte per-cookie limit is no longer a
  concern and needs no measurement.

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
host-only cookie on `localhost` with `Path=/` **is sent to both local services**. The BFF's cookies
therefore reach the local API as well as the BFF, and each carries a bearer identifier.

**This is an accepted local-development exception, not cookie isolation, and must never be described
as isolation.** It is acceptable only because both local services are trusted, the BFF's cookie names
cannot collide with `laravel_session` or `XSRF-TOKEN`, the API authenticates from
`Authorization: Bearer` alone (**D-6**, **D-8**), and Laravel discards cookies it cannot decrypt, so a
BFF cookie arriving at the API is inert.
The API must still never log cookie headers, because a BFF identifier is a bearer secret wherever it
lands.

`127.0.0.1` is deliberately not used alongside `localhost`: it is a different cookie host, and mixing
the two silently splits the cookie jar. ANT-29 set `APP_URL` to `http://localhost:8080`; the port is
still published on the `127.0.0.1` loopback interface, because the bind address and the origin are
separate concerns.

### What production requires

Distinct hosts, and cookies that stay **host-only with no `Domain` attribute**, which the `__Host-`
prefix enforces. A `Domain` attribute would widen a cookie to the parent domain and hand the BFF's
identifiers to the API host on every request; omitting it keeps them on the BFF host, which is the
isolation the local environment cannot provide.

## Token lifetimes

| Token or record | Lifetime | Rotation |
|---|---|---|
| Access token | 15 minutes | Replaced on refresh |
| Refresh token | 14 days | Rotated on every use |
| BFF session record and cookie | 14 days from login, non-sliding | Never; a new identifier on every login |
| OAuth transaction record and cookie | Initially 10 minutes | Single-use |

Token lifetimes are Passport configuration (**D-11**); session and transaction lifetimes are the
BFF's (**S-5**).
A 15-minute access token keeps the refresh path exercised often enough that its failure modes are
visible in normal use rather than only at the 14-day boundary.

## Status code behavior

**`401` is the only status that may trigger a refresh (D-12), and every other status is surfaced to
the browser unchanged (D-13).** The payments specifications own what each one means.

| Status | BFF action |
|---|---|
| `401 unauthenticated` | Refresh once under the coordination above, replay the original request once |
| `403` | Never refresh. Not produced today: no scopes are defined (**D-14**) |
| `404 payment_not_found` | Never refresh. Surface as not found |
| `409 idempotency_payload_mismatch` | Never refresh. Surface the problem document |
| `422 validation_failed` | Never refresh. Surface the problem document |
| `503 projection_rebuilding`, `503 idempotency_contention` | Never refresh. Honour `Retry-After` |

The recovery read `GET /api/payment-intents` answers only `200`, `401 unauthenticated`, and `503 projection_rebuilding`, each handled by the rows above.
A `200` lets the BFF conclude that an intent is absent only when `truncated` is `false` and the timing rules of the [recovery read](../payments/projection-and-query.md#recovery-read) hold; neither a `503` nor a `200` with `truncated: true` does.

Refreshing on anything but `401` would turn an authorization, tenancy, validation, or availability
answer into a credential problem and rotate tokens for no reason.
The tenant-hiding `404` matters most: it is what a merchant receives for another merchant's payment,
and treating it as a credential failure would both act on that distinction and churn tokens.

The BFF cannot tell an expired token from a revoked one, because both arrive as the same `401`
problem document.
It does not need to: one refresh attempt resolves the first case and fails cleanly in the second.

The statuses the BFF itself answers, for a loser whose wait expired or for an unavailable Redis, are
`ledgerflow-spa`'s to define; they must be distinguishable from an authentication failure, and
neither may start an authorization request.

## Browser-to-BFF CSRF and origin validation

The session cookie is the credential for BFF routes, so every state-changing BFF route defends
itself:

- Every mutating route, logout included, accepts only non-`GET` methods.
- `Origin` must be present and equal the BFF's own origin exactly; `Sec-Fetch-Site`, where the browser
  sends it, must be `same-origin`.
  A request failing either is refused before the session record is read or written.
- `SameSite=Lax` additionally withholds the cookie from cross-site `POST`.

**The two OAuth navigation endpoints are the only `GET` routes that change state**, because OAuth
defines them as top-level navigations: starting an authorization request, and the callback.
Neither can be protected by `Origin`, and the callback in particular arrives from the authorization
server's host.
They are protected instead by the transaction binding: a start creates only a new transaction and
touches no existing session, and a callback succeeds only against a live single-use transaction
record named by the browser's own OAuth cookie and matching `state`.

Laravel's CSRF middleware is irrelevant to this boundary.
Merchant resource routes carry no session and no cookie credential (**D-8**), so there is nothing
for a cross-site request to ride.
The BFF-to-API hop is server-to-server and depends on no browser CORS behavior at all (**D-6**).

## Secret hygiene

No access token, refresh token, PKCE verifier, client secret, password, session identifier, OAuth
transaction identifier, cookie value, or Redis credential may appear in logs, error responses, URLs,
rendered content, source control, telemetry, or browser-readable storage.

**Protocol-required query parameters are the only URL exception.**
OAuth necessarily carries `state` and the `code_challenge` in the authorization request to
`/oauth/authorize`, and `code` and `state` in the callback URL.
Codes are single-use and short-lived, and PKCE binds them to the verifier, but that does not make
them safe to record, so:

- the BFF callback's query string is not logged;
- on the API, the Nginx access log records no request query string for any route, as the
  [runtime contract](../../operations/runtime-contract.md#access-logs) defines; the Nginx error log
  may still record the raw request line, query included, during a warning or error tied to a
  request, which remains an unresolved limitation listed under
  [open items](#open-items-owned-by-the-implementation-tasks);
- the callback redirects immediately to a clean URL after processing, whatever the outcome, so no
  page is ever rendered at the URL carrying `code` and `state`;
- every response involved in the callback sends `Referrer-Policy: no-referrer`, so neither value
  leaks through a `Referer` header;
- neither `code` nor `state` appears in application logs, errors, telemetry, rendered content, or
  browser-readable storage.

Three further paths need specific care:

- Cookie headers reach both local services, so neither may log them.
- Redis key names are visible to operational tooling and must not contain a recoverable identifier.
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
  Keeping tokens out of the browser narrows how a refresh token can be captured; it does not change
  this ceiling.
  This specification claims rotation, and nothing stronger.
- **A copied session identifier is a live credential until its record ends.**
  Logout ends it when Redis answers; a logout during a Redis outage does not, and nothing here claims
  otherwise.
- **Redis is a hard dependency of merchant authentication.**
  An outage denies access to every merchant until it recovers.
- **A crash between rotation and the record write loses the session.**
  It forces re-authorization; nothing recovers it.
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

Everything ANT-29 owned in this repository is done: the merchant login page and its throttle, client
secret hashing, key storage and rotation, the `passport:purge` schedule, Sanctum revocation and the
`make demo-token` replacement, and the CORS default.
See [development environment](../foundation/development-environment.md#passport-as-actually-installed)
for what was installed.

Owned by `ledgerflow-api` as a separate follow-up outside ANT-33, pending an observability decision:

- the Nginx error log, which may still record the raw `/oauth/authorize` request line, query
  included, during any warning or error tied to that request. ANT-33 neither suppresses nor
  raises the `error_log` level; it makes only the access log query-free, as the
  [runtime contract](../../operations/runtime-contract.md#access-logs) records.

Owned by **ANT-31** in `ledgerflow-spa`:

- the session and OAuth transaction record schema, including the version and lifecycle-state
  encoding and how keys are derived from identifiers;
- the lock expiry and the bounded loser wait;
- the BFF's own status codes for a loser whose wait expired and for an unavailable Redis;
- the browser compatibility check for `Secure` `__Host-` cookies on `http://localhost`.

Owned by a **`ledgerflow-infra`** task, which blocks deployment but not local development:

- the ElastiCache topology, including whether the BFF shares the planned replication group or gets a
  dedicated one;
- persistence, replication, capacity, and the alerting that `noeviction` requires;
- the BFF's ACL credentials and TLS, and runtime delivery of those credentials and the client secret.

Owned jointly:

- the actual production hostnames for the BFF and the API. The shape is fixed above: two distinct
  hosts, host-only cookies, no `Domain` attribute. Local origins are fixed above too.

## Related

- [ADR 0006](../../adr/0006-merchant-oauth-bff-authentication.md) - the original decision record,
  partly superseded, with its alternatives and consequences.
- [ADR 0007](../../adr/0007-opaque-bff-session-custody.md) - opaque BFF session custody, which
  supersedes **D-4** and parts of **D-15**.
- [Interfaces and realtime](../../architecture/interfaces-and-realtime.md) - surface ownership and
  the WebSocket boundary.
- [Deployment boundaries](../../architecture/deployment-boundaries.md) - `ledgerflow-infra` ownership
  of ElastiCache.
- [Payments projection and query](../payments/projection-and-query.md) - the merchant-scoped read
  behavior behind the tenant-hiding `404`.
- [Payments initiation and idempotency](../payments/initiation-and-idempotency.md) - the write
  contract the BFF forwards unchanged.
- [Development environment](../foundation/development-environment.md) - what is actually installed
  today, including Passport and how it is wired.
