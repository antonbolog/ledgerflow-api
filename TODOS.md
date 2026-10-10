# TODOS

## Payments Infrastructure

### Cut over API workers atomically on the first deploy of required payment intents

**What:** Drain old API workers, or cut over all at once, so no headerless initiation commits after
the ANT-35 recovery guarantee starts.

**Why:** A rolling deploy lets old workers keep writing receipts without an intent, and those
payments never appear in `GET /api/payment-intents`.

**Context:** ANT-35 requires `Ledgerflow-Intent-Id` on new initiations and ADR 0008 states that
the recovery guarantee covers only intents committed after every API worker runs that code. The
API repository only states the boundary; the deployment topology that must honor it belongs to
`ledgerflow-infra`. No deployed environment exists yet.

**Effort:** S
**Priority:** P2
**Depends on:** ANT-35 Phase 2 and the `ledgerflow-infra` deployment task.

### Evolve the outbox relay to claim/lease publishing when 12A reaches its measured limit

**What:** Replace lock-through-Redis-push with a short database claim, publication outside the
transaction, token-guarded finalization, and expired-claim recovery.

**Why:** Preserve API database capacity and scale relay throughput if measured Redis latency makes
the initial row-lock-through-push design miss its documented service targets.

**Context:** The first slice intentionally implements the simpler 12A design. Do not build 12B in
anticipation of load. Reopen architecture review only when controlled load tests or production-like
telemetry meet an evolution trigger in
`docs/specifications/payments/outbox-delivery.md`, and only after timeout, batch-size,
and index tuning fail to restore the target. The eventual design must include claim tokens and
expiry, short claim transactions, Redis publication outside the transaction, token-guarded
finalization, expired-claim recovery, and crash tests after claim, publish, and before finalize.
The benefit is shorter database lock/connection occupancy; the cost is a larger state machine and
more recovery paths.

**Effort:** L
**Priority:** P3
**Depends on:** Completed Payments resilience slice plus load/telemetry evidence that attributes an
SLO failure to lock-through-push.

### Add resumable zero-downtime projection rebuild when gated replay becomes too costly

**What:** Rebuild `PaymentDetails` into staging tables with durable checkpoints, catch-up, and an
atomic final swap instead of keeping the Payments projection gate closed for the full replay.

**Why:** Avoid unacceptable Payments API downtime and full restart cost when event history grows
beyond the operating range of the initial gated batch rebuild.

**Context:** The first slice deliberately uses a gated, bounded-batch in-place rebuild. It is easy
to reason about, never exposes partial state, and restarts from zero after failure. Revisit this
only when measured rebuild duration, stored-event volume, or repeated restart cost violates an
availability target. A future design must cover staging schema lifecycle, checkpoint authority,
events arriving during replay, final catch-up, atomic swap, old-table cleanup, and crash recovery
at every transition. The benefit is near-zero read downtime and resumability; the cost is a much
larger consistency state machine.

**Effort:** XL
**Priority:** P3
**Depends on:** Completed gated rebuild plus measured evidence that full replay or restart duration
is operationally unacceptable.

### Schedule projection verification and corruption alerting after deployment

**What:** Run `payments:projections:verify` periodically and alert an operator when it reports
confirmed corruption.

**Why:** Detect missing receipts, orphan rows, outbox-envelope mismatches, and projection drift
before they are discovered through user-visible behavior.

**Context:** The first slice provides a read-only manual/demo verifier that distinguishes normal
delivery lag from confirmed inconsistency and never starts rebuild automatically. Add scheduling
only after a deployed environment has scheduler-health monitoring and an alert destination. The
scheduled audit must remain bounded, avoid false alerts for in-flight events, expose its high
watermark and duration, and notify rather than mutate data. Evaluate incremental or sampled audits
if a full pass becomes too expensive.

**Effort:** M
**Priority:** P3
**Depends on:** Deployed environment, healthy scheduler telemetry, and a configured alert channel.

## Completed
