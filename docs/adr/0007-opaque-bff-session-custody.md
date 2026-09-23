# 0007 - Hold merchant OAuth tokens in a server-side BFF session behind an opaque cookie

- **Status** - Accepted as specification.
  Supersedes ADR 0006 **D-4** in full, and **D-15** in the parts named below.
  Implementation belongs to ANT-31 in `ledgerflow-spa`; the deployed session store belongs to a
  separate `ledgerflow-infra` task, which blocks deployment but not local development.
- **Date** - 2026-09-23.
- **Decision owner** - AB.
- **Implements into** - ANT-31 in `ledgerflow-spa` and a `ledgerflow-infra` session-store task.
  This repository changes no code, dependency, migration, or wire contract.

## Context

[ADR 0006](0006-merchant-oauth-bff-authentication.md) **D-4** placed the access and refresh tokens in
two BFF-owned `Secure` `HttpOnly` cookies, which made the browser cookie jar the authoritative token
store.
ANT-29 then made Passport refresh rotation a compare-and-set, so of two concurrent refreshes carrying
one refresh token exactly one receives a successor pair and the other receives `invalid_grant`.

The pre-implementation audit and design review for ANT-31 found that D-4 cannot deliver a correct
BFF on the runtime it targets:

- **A cookie jar cannot coordinate a fleet.**
  Several Next.js processes serving one browser each see only the cookies of their own request, so
  none of them can learn that another already rotated the pair.
  The loser's `invalid_grant` is terminal by specification, so the merchant is logged out by an
  ordinary race.
  Correct multi-process refresh needs shared state whichever way the tokens are held.
- **Server Components cannot write cookies.**
  A refresh observed during a server render cannot persist a rotated pair into cookies, so under
  D-4 every server-rendered `401` either loses the successor pair or forces a redirect round trip.
- **Token cookies carry cost that exists only because they carry tokens:** value encryption, a
  4096-byte size ceiling a JWT approaches, and two deletion paths that must agree.

ADR 0006 recorded "a server-side BFF session store with an opaque session cookie" as its rejected
escape hatch if the refresh-concurrency ceiling proved unacceptable.
This record takes it.
It reverses a core custody decision rather than clarifying one, so it supersedes rather than amends.

## Decision

**The BFF holds the access and refresh tokens only in a server-side session store, and the browser
holds one opaque session identifier.**
Every sequence, record requirement, cookie attribute, failure rule, and open item is canonical in
[merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md) and
is deliberately not restated here.
Decisions are numbered **S-n** so they cannot be confused with ADR 0006's **D-n**.

| # | Decision |
|---|---|
| S-1 | Access and refresh tokens live only in a BFF session record in a shared server-side store; the browser holds an opaque session identifier. Supersedes **D-4**. |
| S-2 | The store is Redis: ElastiCache provisioned by `ledgerflow-infra` in deployed environments, and a Redis of the same engine version locally. `ledgerflow-spa` owns the record schema, TTLs, key namespace, refresh coordination, and client code; `ledgerflow-api` owns none of this state. |
| S-3 | Session identifiers and OAuth transaction identifiers are high-entropy random bearer secrets. They are never logged or otherwise exposed. Cookies carry nothing else, so cookie values are not encrypted. |
| S-4 | The PKCE verifier, `state`, safe return path, and expiry of one authorization attempt live in a single-use OAuth transaction record keyed by the transaction identifier, not in a cookie. |
| S-5 | Two cookies exist, `__Host-ledgerflow_session` and `__Host-ledgerflow_oauth`, with identical attributes in every environment: `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, no `Domain`. |
| S-6 | Refresh happens wherever a `401` is observed, including a server render, and writes only to the store. The session cookie is written at the callback and cleared at logout, and never on refresh. |
| S-7 | Correctness of concurrent refresh rests on a versioned compare-and-set on the session record and on Passport's rotation compare-and-set. A Redis `SET NX PX` lock is coordination that keeps losers away from Passport, not a correctness boundary. |
| S-8 | A terminated session is a durable state that no refresh can restore. |
| S-9 | Logout always clears the browser cookie. Terminating the record, deleting it, and revoking the tokens at Passport are best effort when a dependency fails, and no guarantee is claimed that a copied session identifier is revoked. Supersedes the parts of **D-15** named below. |
| S-10 | The BFF fails closed when Redis is unavailable: no request is authenticated from a record it cannot read, and loss of the store forces re-authorization and never grants access. |
| S-11 | The Redis contract requires database 0 with cluster-compatible operations, a dedicated key namespace with separate ACL credentials, TLS in deployed environments, and `noeviction` with capacity monitoring and alerting. |
| S-12 | `ledgerflow-api` adds no `GET /api/auth/me`. The BFF treats "authenticated" as "holds a live session record" and never decodes the access token. |

### What of ADR 0006 is superseded, and what stands

- **D-4 is superseded in full** by S-1 and S-5.
- **D-15 is superseded in two parts.**
  Its step 2, "clears the BFF cookies", becomes S-9: one session cookie is cleared and the session
  record is terminated and deleted.
  Its unconditional claim that logout revokes the active tokens becomes best effort under dependency
  failure, per S-9.
  `POST /api/auth/logout` as the revocation call, and ANT-29's amendment of step 3, stand unchanged.
- **Every other ADR 0006 decision stands**, including D-5, D-7, D-8, D-11, D-12, and D-13.
  D-5 is strengthened: no token reaches the browser even in encrypted form.
- ADR 0006's rejection of "a server-side BFF session store with an opaque session cookie" is
  reversed by this record, for the reasons in Context.

### Why the Redis lock is not the correctness boundary

`SET NX PX` expires by wall clock, so a process that stalls past the lock's expiry can still be
inside its refresh when a second process acquires the lock.
Two properties keep that case correct without the lock:

- Passport's rotation compare-and-set lets exactly one of them rotate; the other receives
  `invalid_grant`.
- The session-record compare-and-set lets a writer store a successor pair only against the version it
  read, so a stale writer cannot overwrite a newer pair or revive a terminated session.

The lock only spares losers the `invalid_grant` round trip; losing it costs latency, never
correctness.

### Why a crash after rotation is session loss, not self-healing

If the BFF crashes, or cannot write the store, after Passport rotated the pair but before the session
record holds the successor, the record keeps a refresh token Passport has already revoked.
The next refresh receives `invalid_grant`, the re-read finds no newer version, and the session ends
with a re-authorization.
The successor pair is held by no participant and expires at its own lifetime.
That outcome is safe, because nothing is granted and nothing is exposed, but the merchant does lose
the session; it is not recovered.

## Alternatives considered

- **Keep D-4's token cookies and add a short-lived distributed refresh-result store.**
  Rejected: it pays the same shared-store cost and still leaves Server Components unable to persist a
  rotated pair, cookie encryption, the size ceiling, and two cookie deletion paths.
- **An in-process session store per Next.js process.**
  Rejected: every deploy replaces the process and logs every merchant out, and a fleet cannot share it.
- **Isolating the BFF by a numbered Redis database and `SELECT`.**
  Rejected: numbered databases do not exist in ElastiCache cluster mode.
- **Relying on Passport's compare-and-set alone, without a lock.**
  Rejected: correct, but every loser spends a token-endpoint call and the BFF client's shared
  throttle budget only to receive `invalid_grant` and recover by re-reading the record.
- **Relying on the lock alone, without record versioning.**
  Rejected: the lock expires by time, so it cannot on its own stop a stale writer from overwriting a
  newer pair or reviving a logged-out session.
- **Encrypting cookie values with AES-GCM.**
  Rejected: cookies carry only random opaque identifiers, which encryption would not make any less a
  bearer secret.
- **`GET /api/auth/me` as a prerequisite.**
  Rejected: tenant scoping is resolved by the API on every request, the JWT `sub` is a user id rather
  than the merchant key, and no current dashboard view needs merchant identity.

## Consequences

- **Refresh becomes correct across a fleet and during a server render**, at the cost of the first
  runtime dependency of `ledgerflow-spa` beyond Next.js and React: a Redis client.
- **Redis becomes a hard runtime dependency of merchant authentication.**
  An outage denies merchant access until Redis recovers; it never grants access.
- **Memory pressure is a correctness concern, not a performance one.**
  Namespace and ACL isolation do not protect sessions from eviction under shared memory pressure, so
  `noeviction`, capacity alerting, and observable write refusal are required.
  Whether that is met in the planned shared replication group or a dedicated one is
  `ledgerflow-infra`'s decision.
- **Logout during a Redis outage does not revoke.**
  The browser is logged out, but a copied session identifier regains the session when Redis recovers,
  until its record expires.
- **A crash between Passport rotation and the store write costs the merchant the session.**
- **The ADR 0006 limitations stand**: no refresh-token replay detection, no cascading family
  revocation, and fleet-wide key rotation.
- **This repository's side is documentation only.**
  The authorization server already provides what the BFF needs: `S256` PKCE, rotation as a
  compare-and-set, and `POST /api/auth/logout`.

## Related

- [ADR 0006](0006-merchant-oauth-bff-authentication.md) - the superseded custody decision, kept as
  historical evidence.
- [Merchant OAuth and BFF authentication](../specifications/merchant/oauth-bff-authentication.md) -
  the canonical specification for this decision.
- [Deployment boundaries](../architecture/deployment-boundaries.md) - `ledgerflow-infra` ownership of
  ElastiCache.
- [Roadmap](../roadmap.md#merchant-dashboard-integration) - ANT-31 and the infrastructure task.
