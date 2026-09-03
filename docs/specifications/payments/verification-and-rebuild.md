# Projection Verification and Rebuild Specification

## Given

`stored_events` is immutable business history. `payment_details`, projector receipts, and outbox
completion markers are derived state that can drift because of a bug, operator action, or an
interrupted process.

## Problem

The application cannot infer corruption merely from elapsed time: a missing projection may still
be valid queue lag. Rebuilding automatically after every discrepancy can destroy evidence, expose
partial data, or repeat external side effects.

## Decision

Verification is read-only and distinguishes in-flight work from confirmed inconsistency. Repair
and rebuild are explicit operator actions. A durable Payments gate plus PostgreSQL advisory locks
prevents traffic from observing or modifying a partial rebuild.

**Delivery order.** T10 owns verification and targeted single-envelope outbox repair, and **both
are implemented**: verification runs all four passes below in one snapshot, and repair reconstructs
or corrects exactly one envelope under the quiescence prerequisite. The projection gate, the rebuild
algorithm, and read-model recovery stay T11's, and the barrier-coordinated process-kill matrix stays
T12's. See the [roadmap](../../roadmap.md) for task state.

## Verification

**Implemented by T10.** `payments:projections:verify` runs all four passes below in one snapshot. A
report's `scanned` names the passes that actually ran, and a report can be built as `complete` only
when it carries all four, so a run that lost a pass is an incomplete run rather than a clean one
with a smaller scope. It is a manual, read-only operator command, not an HTTP endpoint, a queued
job, or a scheduled task. It never mutates
data, never starts repair or rebuild, and never invokes the aggregate, event dispatch, Spatie replay,
the consumer, or the outbox writer as a shortcut for reading derived state. It may run manually and
later on a schedule, once deployment monitoring and an alert destination exist.

`stored_events` is canonical business history. The outbox, projector receipts, `payment_details`, and
the completion marker are derived state. `payment_idempotency_receipts` is a separate,
non-reconstructable HTTP contract; neither verification nor repair reads or changes that table.

### Snapshot and scope

One explicitly owned `REPEATABLE READ, READ ONLY` transaction on one connection covers the whole
audit. Inside it the command captures `high_watermark`, the maximum **visible** global
`stored_events.id`, and `audit_at`, a fixed audit timestamp; the sequence's allocated value is not the
watermark. Every read-model, receipt, and outbox check, including the reverse scans, sees that same
snapshot. At Read Committed each statement could observe a different committed state, which is why the
isolation level is owned explicitly rather than inherited. See
[transaction isolation](https://www.postgresql.org/docs/18/transaction-iso.html).

The global ID is not a commit-order fence: a lower allocated ID can become visible after the snapshot
opens. The verifier therefore reports the rows visible in one snapshot, not a permanent assertion that
every ID below the watermark has committed. Late commits fall outside a run's claim and are picked up
by the next run. Every invocation is a fresh audit, not a resumable incremental checkpoint.

Four passes run inside that one snapshot:

1. canonical Payments events in `stored_events`, ordered by global `id` through `high_watermark`. It
   reads the derived envelope, projector receipt, and read row for each canonical event it visits,
   keyed by that event's own identity, and it also detects a read row for the same payment that was
   projected from a different source event.
2. `payment_projector_receipts` for the named `payment_details` projector, keyset by `event_id`.
3. `payment_details`, keyset by `payment_id`.
4. `payment_outbox`, keyset by `event_id`.

A fifth, non-walking check runs beside them: one bounded aggregate reports canonical Payments events
that share a durable event id. Two such rows can fall in different batches, so a forward walk could
only find them by holding every id it had seen, which the bounds forbid; PostgreSQL groups them
without transferring history into PHP, and the exact total is reported even when only a bounded
sample of ids is carried back.

**Each reverse pass resolves on the key whose corruption it owns**, so one damaged record normally
yields one finding rather than an accusation from every direction:

| Pass | Resolves canonical existence on | Because |
|---|---|---|
| Receipts | the envelope's `aggregate_uuid` | A receipt has no identity of its own; its envelope's primary key is its only link, and the envelope's payment is what canonical history can be asked about. A corrupted envelope *version* must not make an intact receipt look orphaned. |
| Read rows | `payment_id` | A wrong `aggregate_version` or `source_event_id` on the row is a field mismatch the forward pass reports, having both sides to compare. |
| Envelopes | `(aggregate_uuid, aggregate_version)` | `payment_outbox` enforces its own unique constraint on exactly that pair because it mirrors the key `stored_events` enforces, so the pair is this table's canonical identity. |

A receipt whose envelope does not exist at all is reported as its own case rather than as an orphan:
the consumer writes a receipt only while holding that envelope's row lock, so the two cannot come
apart through an interrupted delivery, and with no envelope there is no identity left to check
canonical history against. Claiming otherwise would report history as checked when it was not.

An envelope with a corrupted `aggregate_version` is reported twice, and deliberately: the forward
pass says the envelope for this event has a wrong column, and the envelope pass says the envelope as
it now stands matches no canonical event. Both are true and an operator needs both, so a finding
count is a count of facts rather than of damaged rows.

Each pass uses its own stable-key keyset cursor, never `OFFSET` and never an in-memory set of every
visited ID. Reverse scans include the nil UUID on their first page, because corruption need not use application-generated identifiers.
Passes 2 to 4 exist because a forward scan of canonical history cannot discover a derived
record that has no canonical source, so they must not depend on pass 1 having reached their event.
Every finding has one deterministic owning pass and check, so the same finding is not counted twice.
Counts describe findings by category, not a globally deduplicated count of corrupt payments; one event
can carry several distinct findings. Unsupported Payments aliases or versions and malformed canonical
payloads are reported, never silently skipped or deserialized into an arbitrary class. Unrelated
bounded contexts stay outside the Payments audit.

Comparison against canonical history covers the source event, merchant, amount, currency, status,
aggregate version, and event time, plus projector-receipt presence, `payment_details` presence, and
agreement with `projection_completed_at`. A canonical payload's durable event id has no canonical
column to be compared against, unlike its payment id, so an event id that is not a canonical UUID is
reported as a malformed payload rather than sent to PostgreSQL as a parameter: one corrupt row must
not abort the audit and hide every other finding behind it. Payload comparison is semantic JSON equality, so key
ordering or textual rendering alone is never a mismatch.

### Classification

| Classification | Meaning |
|---|---|
| `consistent` | Canonical history and the required derived records agree. |
| `in_flight` | A valid canonical envelope exists, projector receipt, read row, and marker are still absent, and the non-contradictory delivery is inside grace. |
| `delivery_overdue` | The same non-contradictory delivery is still incomplete at or beyond grace. Age alone is not corruption evidence. |
| `exhausted` | Delivery is stopped by the existing poison protection, not by ordinary healthy lag. |
| `inconsistent` | A concrete contradiction exists: immutable-field mismatch, orphan state, receipt without its row, marker without its receipt, or a missing canonical envelope. |

Grace never suppresses a concrete inconsistency, including for a very recent event. T6 commits the
stored event and its initial envelope atomically, so a visible event with no envelope is never
explainable as ordinary queue lag.

Verification owns its own configurable grace, initially 30 seconds, separate from the relay's
reconciliation grace in [outbox delivery](outbox-delivery.md#state-transitions). Age runs from
canonical `occurredAt` to the fixed `audit_at`, compared as timestamp instants rather than display
strings: `age < grace` is in-flight and `age >= grace` is overdue. This is event age, not measured time
since commit, and it is never derived from `last_published_at` or `available_at`, because
republication must not rejuvenate an old event indefinitely. A future canonical `occurredAt` on an
otherwise non-contradictory incomplete delivery produces the operational warning
`event_time_in_future`, not an inferred corruption finding and not an ordinary in-flight
classification.

### Exit codes

| Exit code | Meaning |
|---|---|
| `0` | Audit completed; all state is consistent or only normal in-flight delivery remains. |
| `1` | Audit completed and found at least one inconsistency. |
| `2` | Audit did not complete, or the invocation or configuration is invalid, including timeout and database errors. |
| `3` | Audit completed with no inconsistency, but overdue delivery, exhaustion, or the event-time anomaly needs operator attention. |

Precedence is `2 > 1 > 3 > 0`, not numerical ordering. A run that fails part way keeps its
already-observed findings in a safe partial summary, and that summary is never presented as a complete
clean audit.

### Bounds

| Limit | Initial value |
|---|---|
| Batch size | 100, configurable within 1-1000. |
| Retained finding examples | 50 for the whole invocation, configurable within 0-200. |
| One SQL statement | 2 seconds. |
| Audit transaction | 120 seconds. |
| One payload transferred into PHP | 64 KiB, enforced before the payload is transferred and decoded. |

These are initial operational limits, not measured throughput guarantees or an SLA. A payload above
the inspection limit makes the audit incomplete with exit code `2`; its uninspected contents are
declared neither consistent nor corrupt. The bounds combine an application work budget with
transaction-local PostgreSQL `statement_timeout` and `transaction_timeout`. Because
`transaction_timeout` terminates the session, a timeout aborts the run rather than reconnecting into a
lost snapshot, releases the snapshot, and persists no progress checkpoint; the server-side limit bounds
database transaction lifetime, not the CLI process and its error reporting. See
[client connection defaults](https://www.postgresql.org/docs/18/runtime-config-client.html#GUC-TRANSACTION-TIMEOUT).

Bounded rows in PHP do not prove bounded database work. The event UUID lives inside
`stored_events.event_properties` JSON rather than in a dedicated indexed column, so a reverse pass
that asked canonical history about a durable event id would need a scan of history per batch, and no
index exists or is proposed for that expression.

**No reverse pass does so, and the plans are measured rather than assumed.** Each resolves through an
aggregate identity column instead, and the owning integration test replays every statement the audit
issued through `EXPLAIN`: no reverse-pass plan references `event_properties`, and each has an
existing aggregate-identity index path into `stored_events`. **T10 therefore adds
no index**, and the Payments schema test's pinned index set is unchanged. The duplicate-id aggregate
is an additional history-wide JSON grouping beside the canonical payload reads.
It returns a bounded sample and exact counts, but its database work still scales with Payments history and is subject to the timeouts.
This is not a claim that an index could never benefit a future query design.

That evidence is a claim about plan shape and index availability at fixture volume, **not a claim
about speed or behaviour at production scale**. Index availability is asserted with sequential scans
disabled, because at fixture size the planner may rightly prefer a scan of a tiny table and asserting
its natural choice would measure the fixture rather than the schema.
The reverse lookups use correlated `LATERAL ... LIMIT 1` rather than an `EXISTS` form that can choose a history-wide hashed subplan per batch.
Tests inspect the first observed bindings for both the inclusive first-page and strict subsequent-page query shapes, not an empty terminal page.

### Result document

Each command emits exactly one JSON object on stdout, with human-oriented diagnostics on stderr.
Common fields are `schema_version: 1`, `operation_id`, `command`, `outcome`, `reason`, and
`duration_ms`, using stable outcome and reason categories rather than raw exception text. The verifier
adds `complete`, `high_watermark`, `audit_at`, `scanned` counts by pass, `classification_counts`,
`finding_counts`, and the capped `examples` with `examples_truncated`. Pass, check, and example
ordering is deterministic.

Before a snapshot is captured, `high_watermark` and `audit_at` are null, not invented values.
Invalid configuration returns `invalid_configuration`; invalid command arguments/options return `invalid_invocation`, with exit `2` and no scan.
`event_time_in_future` is counted as an operational finding, not ordinary `in_flight`, so classification counts alone need not sum to all scanned events.

Finding examples carry only categories, owned field names, and validated identifiers. Neither stream
nor any log carries actual or expected payment values, raw payloads, raw exception text, idempotency
keys, or credentials.

## Targeted outbox repair

**Implemented by T10.** `payments:outbox:repair {eventId} --delivery-quiesced` is an explicit,
audited, manual operator action that reconstructs or corrects **exactly one** derived outbox envelope
from canonical stored history. It never creates or changes an idempotency receipt, a projector
receipt, a read row, canonical history, or any other envelope. Bulk repair is separate reviewed scope.

### Delivery quiescence

T10's repair is an offline delivery operation, while verification may run with delivery active. The
command requires an explicit `--delivery-quiesced` acknowledgement and refuses before opening any
database transaction without it. The acknowledgement is recorded in the audit as the operator's
assertion; it is not machine-verified proof of global quiescence.

The prerequisite is necessary because a recreated envelope takes `projection_failure_generation` `0`
again, a value a delayed failure recorder from the deleted incarnation can still hold, and a row lock
alone cannot tell the two incarnations apart. Online-safe resurrection would need a wider durable
fencing design. **T10 adds no provisional gate, fence table, or control row**; T11 revisits repair and
verification participation when it introduces the real projection gate and rebuild.

The operator procedure is:

1. Stop the scheduler and Horizon together through `make projection-stop`.
2. Confirm that every relevant delivery and failure-recorder process has stopped and drained,
   including standalone consumers, manual commands, and processes on other hosts. Stopping the local
   pair does not establish the absence of external or manual workers, and the command discovers no
   workers of its own.
3. Invoke the repair with the explicit acknowledgement.
4. Run verification, evaluate the remaining findings, and resume delivery through
   `make projection-start`.

### Identity and transaction

Repair resolves exactly one canonical event by its durable event UUID. Zero matches and multiple
matches are both refusals: it never picks the first match and never infers identity from a corrupted
envelope. Exactly one match still has to pass alias, version, payload, and aggregate-identity
validation, and a supplied identifier that is not a canonical UUID is refused before any database
work rather than sent to a `uuid` column as a parameter.

**This lookup is the one unindexed shape in T10, and that is measured rather than overlooked.** The
durable event id lives only inside `stored_events.event_properties`, so unlike every verification
pass this predicate has no index and none is proposed. The command cannot avoid it: it is handed an
event id and must not infer identity from the envelope it is repairing. `LIMIT 2` is the ambiguity
check rather than a bound, which also means there is no early exit - proving a match is unique costs
the whole scan even when the row is found immediately.

Two fixture measurements exist, taken on one containerised PostgreSQL 18 instance: about 6 ms over
22,000 canonical rows, and about 18 ms over 200,000. **They establish no production capacity
threshold** - not a supported history size, not a throughput figure, and not the point at which the
command's 2-second statement timeout would fire. They record the plan shape and its cost on those
fixtures and nothing further; extrapolating them would assume a linearity, a hardware profile, and a
cache state none of them measured.

When the statement timeout does fire, the lookup is **not** a refusal. It is a failed run: exit `2`,
a `statement_timeout` reason, and a mutation state reporting what actually happened to the
transaction - `rolled_back` when the rollback was confirmed, `unknown` when it was not. Nothing is
written either way, but "nothing was written" is not the same answer as "the command declined", and
the exit code keeps them apart.

**T10 adds no index**, and the owning integration test pins the plan so that an index appearing
later is a deliberate migration decision rather than a detail.

The command owns one short `READ COMMITTED` transaction on one connection: resolve the canonical
event, lock its stored-event row, revalidate canonical identity, then lock and read any existing
envelope before applying the permitted mutation and committing. Read Committed is deliberate and
unlike the verifier's snapshot: after waiting on the canonical lock this transaction must SEE a
competing repair's committed result, which a snapshot taken before the wait would hide. Locking the canonical row serializes
two concurrent repairs even when no outbox row exists, and does not authorize modifying canonical
history; after waiting, the second repair reads the first one's committed result rather than
overwriting it from a stale snapshot. A unique-key or aggregate-identity conflict with another
envelope is a visible refusal, never permission to delete or move that row. Local
`statement_timeout = 2s` and `transaction_timeout = 10s` apply, and neither a failed mutation nor an
unknown commit outcome is retried automatically.

### Mutation matrix

Repair fixes the envelope, not the read model and not its failure history. All permitted changes
happen atomically, and an existing row is never deleted and reinserted merely to obtain clean
defaults.

| Existing-envelope fields | Permitted behavior |
|---|---|
| `event_id` | Preserve the primary key; never rename or move another envelope. |
| `event_type`, `schema_version`, `aggregate_uuid`, `aggregate_version`, `payload`, `occurred_at` | Restore from the validated canonical stored event. |
| `last_published_at`, `publish_count`, `consecutive_transport_failures`, `last_transport_error` | Preserve transport and publication history. |
| `projection_failure_count`, `last_projection_error`, `projection_failure_generation`, `exhausted_at` | Preserve failure history, generation, and exhaustion. |
| `created_at` | Preserve. |
| `updated_at` | Change only when a real repair mutation occurs. |

Completion is derived from the receipt for the named `payment_details` projector and the target event.
When that receipt exists, an existing non-null marker is preserved and a missing marker is filled from
the receipt's `created_at`; when it does not exist, the marker must be null and an erroneous non-null
marker is cleared. A receipt owned by another projector establishes nothing for this projection. A
receipt without its read row remains corruption that this command does not fix: **read-model recovery
stays T11's**, and repair never creates or modifies projector receipts or read rows.

Eligibility follows the writer-ownership split
[outbox delivery](outbox-delivery.md#envelope-and-active-index) already records:

- When canonical fields or the marker actually change, no named receipt exists, and the envelope is
  not exhausted, `available_at = now()` is set in the same transaction.
- **An exhausted envelope keeps its availability and its `exhausted_at`.**
  `payments:outbox:retry {eventId}` remains the sole reactivation path after correction; repair is
  not an implicit force-redelivery command and cannot bypass the poison boundary or the
  `projection_failure_generation` fence.
- A completed envelope keeps its availability, because the completion marker already excludes it from
  relay eligibility.
- When canonical fields and the marker are already correct, the result is `unchanged`, touching
  neither timestamps nor transport backoff.

`changed_fields` names the values a repair DECIDED, not every column its statement touched: the
primary key is the identity being repaired, and `created_at`/`updated_at` change on any write by
definition, so listing them would make the field differ in kind between a created row and a
corrected one.

For a **missing** envelope the command inserts exactly one canonical row: immutable fields derived
from canonical stored history; completion from the named receipt's `created_at` when it exists, and
otherwise null and immediately eligible; publication and failure counters and generation at their zero
defaults with null exhaustion, publication, and error history; operational creation, update, and
availability timestamps at repair time, with canonical event time retained in `occurred_at`. That
result reports `history_reinitialized: true`, because deleted delivery history - including a possible
former exhaustion - cannot be reconstructed from a stored event. Existing-row repair and no-op results
report `history_reinitialized: false`.

A successful repair is a claim about one envelope, not about the payment's whole derived state and not
about successful delivery.

### Audit and outcomes

Repair adds `event_id`, `delivery_quiesced`, `mutation_state`, `changed_fields`, and
`history_reinitialized` to the common result document. `changed_fields` carries field names only, never
previous or replacement values; unknown values are null rather than fabricated zeros; an invalid
supplied event ID is never echoed into logs.

| Exit code | Meaning |
|---|---|
| `0` | `created`, `repaired`, or `unchanged`, with the final audit write reported successfully. |
| `1` | Expected refusal: absent or ambiguous canonical history, identity conflict, or missing quiescence acknowledgement. |
| `2` | Invalid input or configuration, database or runtime failure, audit failure, or unknown commit outcome. |

The audit is **structured application logs, not a new table**. Operator intent is recorded before any
possible mutation, and the final outcome, safe reason, changed-field names, and mutation state
afterwards, correlated through one `operation_id` that also carries the quiescence acknowledgement.
Refusals mutate nothing and carry a safe reason.

| Failure boundary | Result |
|---|---|
| Initial intent logging fails | The mutation never begins; exit `2`. |
| Failure before commit with confirmed rollback | `mutation_state: rolled_back`; exit `2`. |
| Commit outcome unknown | `mutation_state: unknown`; exit `2`, with no automatic retry. |
| Commit confirmed, final audit logging fails | `mutation_state: committed`, `reason: audit_failed`; exit `2`, without claiming rollback. |
| No-op completed, final audit logging fails | `mutation_state: no_changes`; exit `2`. |
| Final audit also fails after rollback or uncertainty | Preserve `rolled_back` or `unknown`; never upgrade either to `committed`. |

Database commit and log persistence are not one transaction, so logging success is not durable atomic
audit retention, and a killed process or unavailable stdout can produce no final JSON at all: absence
of an answer is not evidence that nothing changed. Resolve uncertainty by inspecting state or running
a fresh verification, never by repeating the mutation automatically. An atomic durable audit ledger
would be a separate schema and design decision.

## T10 implementation evidence

The operator procedure lives in [Runtime Contract](../../operations/runtime-contract.md#manual-verification-and-targeted-repair).
The following evidence is owned by executable tests, not by the existence of this document.

| Contract | Owning executable evidence |
|---|---|
| Canonical field/identity mismatches, grace versus overdue/exhaustion, future-time warning | [PaymentProjectionVerifierTest](../../../Modules/Payments/tests/Integration/PaymentProjectionVerifierTest.php) |
| Independent reverse passes, orphan findings, duplicate UUIDs, bounded examples, nil-UUID first-page inclusion | `PaymentProjectionVerifierTest`: reverse coverage, cross-batch duplicate and nil-UUID cases |
| One read-only snapshot, lower-ID late commit excluded then found by a fresh run, real statement cancellation and backend loss | `PaymentProjectionVerifierTest`: snapshot and timeout cases |
| Actual reverse SQL plans, both first and subsequent keyset pages | `PaymentProjectionVerifierTest`: the two reverse-plan assertions; index availability is not a throughput guarantee |
| Canonical-only repair, ambiguity/conflict refusal, mutation matrix and unrelated-state preservation | [PaymentOutboxRepairTest](../../../Modules/Payments/tests/Integration/PaymentOutboxRepairTest.php) |
| Canonical row contention, explicit READ COMMITTED despite a different session default, refusal to borrow an outer transaction | `PaymentOutboxRepairTest`: locking and transaction-ownership cases |
| Intent/final audit failures, commit uncertainty, combined transaction and log failures, unchanged acknowledgement | `PaymentOutboxRepairTest`: audit cases; [RepairOutcomeTest](../../../Modules/Payments/tests/Unit/RepairOutcomeTest.php) for exit mapping |
| Invalid config/invocation and malformed repair ID produce exit 2 without raw input disclosure | Both integration suites' command-boundary cases |
| Semantic JSON comparison and bounds validation | [VerificationEnvelopeTest](../../../Modules/Payments/tests/Unit/VerificationEnvelopeTest.php), [VerificationConfigurationTest](../../../Modules/Payments/tests/Unit/VerificationConfigurationTest.php) |

The repair contention test uses a held canonical-row lock on another connection and observes a real timeout.
The committed-predecessor case and explicit isolation test are separate controls, not a barrier-coordinated two-process repair race.
Commit uncertainty is injected at the framework commit boundary; this proves conservative reporting, not every possible network failure during COMMIT.
The transaction-timeout test establishes PostgreSQL session termination separately from the verifier's coordinated backend-loss test.
The 22k/200k repair timings above are earlier supporting measurements, not fixtures replayed by every suite run.
No production-scale SLA, global quiescence detection, atomic durable audit, or T11/T12 completion follows from these tests.

### Final local validation checkpoint

The final production diff passed the `make check` stage inside `make ci`: 621 tests and 3347 assertions, Composer validation, Pint, Rector, PHPStan level 10, and instruction parity checks.
CI guard and development-database isolation checks passed.
The nested `make projection-contract` passed in 113 seconds against its 180-second work deadline; subsequent checks found no disposable project containers or volumes.
The aggregate `make ci` invocation exited 2 at the image stage because the sandbox denied Docker Buildx activity-file access.
Only the remaining repository-defined `make image-contract` target was retried with the required filesystem permission; it exited 0, completing the production and web image checks.
These are successful component results from a split execution, not a claim that one uninterrupted `make ci` invocation exited 0.

### Post-review checkpoint

An independent review of the checkpoint above produced three changes, all of them after that validation ran.

The verifier now refuses to run inside a transaction it does not own.
`DatabaseProjectionVerifier::verify()` returns an incomplete report with the `database_error` reason when `transactionLevel()` is not zero, which is the guard `DatabaseOutboxRepair::apply()` already had.
The snapshot, the `READ ONLY` declaration and the transaction bounds are the adapter's to own, and a savepoint carries none of them.

That guard is pinned by `PaymentProjectionVerifierTest`: "does not borrow an outer transaction or audit inside its savepoint".
Its control is the `TransactionBeginning` event count, not the reported fields.
With the guard disabled the test was observed to fail on that counter, and on nothing before it.
The remaining assertions would not have distinguished the two paths: the isolation statement fails either way, its `25001` is not on the verifier's mapped list and becomes `database_error`, `rollBack()` restores the caller's level to `1`, and no pass ever starts, so `scanned` stays empty and the watermark stays null.
That derivation is why the event counter carries the control rather than the reported fields.

This is deliberately **not** a claim that an unguarded audit would leak `READ ONLY` into the caller's transaction.
Measured on this PostgreSQL, `BEGIN; SAVEPOINT s1; SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY;` fails with `SET TRANSACTION ISOLATION LEVEL must not be called in a subtransaction`, after only `BEGIN` and `SAVEPOINT` and therefore with no prior query to have taken a snapshot.
The combined statement fails as a whole and applies neither characteristic, so no `READ ONLY` reaches the caller's transaction.
The guard is justified by transaction ownership and by reporting the cause rather than the symptom, not by preventing a leak.

Two comment-only corrections accompany it, with no SQL and no behavior change.
`DatabaseOutboxRepair::identityHeldByAnotherEnvelope()` previously claimed its unlocked read and the write it guards see the same state.
They do not, and the safety property holds for a different reason: the conflict set can only shrink, because a repair only ever writes its own canonical row's `(aggregate_uuid, aggregate_version)`, `stored_events` is unique on that pair, and no other writer touches those two columns.
A stale read therefore costs at most a conservative refusal, and `FOR UPDATE` would add nothing because it cannot lock a row that does not exist yet.
`RepairReason::MalformedEventId` moved from the refusal group to the failure group, where its existing `failed` outcome and exit `2` already belonged; `isRefusal()`, the outcome mapping and the owning test are unchanged.

These three changes were validated by a **separate `make check` invocation only**, which exited `0` with 622 tests and 3356 assertions.
That is one more test and nine more assertions than the checkpoint above records.
`make ci`, `make projection-contract` and `make image-contract` were **not** re-run, so the split-execution evidence above stands as written and is not extended by this checkpoint.

## Projection gate

**T11's, and not implemented.** T10 adds no provisional control row, advisory key, or gate check.

`payment_projection_controls` stores one row per projection with `ready` or `rebuilding`, owner
token, start time, and timestamps. The row is durable operator-visible state; advisory locks own
mutual exclusion.

- Payments POST, transactional GET, relay, and consumer acquire a transaction-scoped shared lock
  and read the control row on the same explicit connection.
- Rebuild holds a session-level exclusive lock on one pinned connection.
- While rebuilding, Payments HTTP returns retryable `503`; relay/consumer work is deferred without
  consuming projection failure budget or transport budget. Health and unrelated modules remain
  available.
- Losing or reconnecting the pinned session aborts the rebuild. Code never assumes the old lock
  survived.

### T7's reciprocal obligation: the gate does not exist yet in the relay

**T11 owns adding it.** T7 shipped `OutboxRelay::relayOne()` — one row, one short transaction,
`SKIP LOCKED`, publish before commit — without acquiring any shared lock or reading
`payment_projection_controls`, because that table, its advisory-key derivation, and this gate's
deferral semantics are this task's design, not T7's. Recorded as HS-1 in
[outbox delivery](outbox-delivery.md#hs-1-no-shared-projection-gate-is-acquired) and in
[ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md).

Concretely, T11 must add gate acquisition as the **first statement** inside `relayOne()`, before
eligibility selection, on the same injected `Connection` the relay already holds — not a second
connection, and not a call wrapped around the existing method, because the shared lock and the row
lock it protects must report the same backend pid the way Q6's evidence already requires. T11 also
adds the deferral semantics on the consumer side of this same design. Until T11 lands, the relay
runs with **no** gate: a rebuild started by hand today would race an active relay pass with nothing
to stop it, which is exactly the gap this section exists to close.

### T8's reciprocal obligation: the consumer arrives without a gate either

**Implemented, and the gate is still absent by design.** T8's consumer exists and ships with **no**
gate: `PaymentProjectionConsumer::project()` opens its transaction by locking and loading the
`payment_outbox` envelope by `event_id`, as [projection and query](projection-and-query.md) records -
for the same reason T7 took none: the control row, its advisory-key derivation, and the deferral
semantics are this task's design. The consumer's own docblock names the insertion point.

**T11 owns adding it here too**, and the obligation runs both ways: T11 prepends connection-affine
gate acquisition to that now-existing consumer transaction, before the envelope lock, on the same
injected `Connection` the consumer already holds - not a second connection and not a wrapper around
the transaction - and adds the consumer deferral semantics beside it. Deferred work consumes no
projection failure budget and no transport budget, per the clause above: a deferral is neither a
confirmed handler failure nor a transport rejection.

### Compatibility evidence for this gate

T4 measured the two clauses above against PostgreSQL 18.4 and Laravel 13.24.0. The result table lives
in [outbox delivery](outbox-delivery.md#blocking-compatibility-evidence) (Q6 for connection affinity,
Q8 for session loss); only what T11 must design around is repeated here.

**Affinity is provable.** A transaction-scoped shared lock and its protected query report the same
backend pid in `pg_locks`, witnessed from a third connection rather than self-reported. Shared holders
coexist across distinct backends, an exclusive contender is refused immediately, and a bounded
`lock_timeout` turns a blocking wait into a real `55P03` — the SQLSTATE already on the Payments retry
allowlist.

**Session loss behaves differently either side of a transaction, and both are dangerous:**

- **Outside a transaction, Laravel reconnects silently.** `Connection::run()` catches the failure,
  `handleQueryException()` rethrows only when `transactions >= 1`, and otherwise
  `tryAgainIfCausedByLostConnection()` reconnects and re-runs
  (`Database/Connection.php:799-828,1010-1040`). Measured: the caller saw a **successful query on a
  new backend pid**, and the advisory lock was gone.
- **Inside a transaction, the loss surfaces** as a `QueryException` — but
  **`transactionLevel()` still reported `1`**. `reconnect()` swaps only the PDO and never touches the
  counter (`Database/DatabaseManager.php:333-345,374-385`), and `disconnect()` leaves it too
  (`Database/Connection.php:1064-1081`).

**Therefore, for the rebuild: neither a successful query nor a non-zero `transactionLevel()` is
evidence that the pinned session, or the advisory lock it took, survived.** Both can be true while the
lock is already held by somebody else. A rebuild that infers ownership from either is unsafe, and the
abort path needs a positive check — re-reading the durable control row on a known-live connection, a
fenced token, or a refusal to reconnect at all. T11 owns that choice; T4 only proves the naive
inference is wrong.

## Rebuild algorithm

**T11's, and not implemented.** Targeted repair reconstructs one outbox envelope and never touches
the read model, so it is not a substitute for this rebuild.

1. Atomically mark the projection `rebuilding` with a unique owner token.
2. Acquire the exclusive advisory lock, waiting for prior shared transactions to finish.
3. Capture the maximum global stored-event ID.
4. Clear only `PaymentDetails` and its projector receipts.
5. Replay Payments events through the watermark in fixed configurable batches using the same
   deterministic application service as the consumer.
6. Recreate projector receipts and commit each batch while the gate remains closed.
7. Mark `ready` only after complete success, release the lock, and drain stale queued duplicates.

Lock timeout aborts before clearing data. Handled error or process death leaves the durable row as
`rebuilding`, even though PostgreSQL releases the session lock. An authorized next run reacquires
the exclusive lock, clears partial derived state, and restarts from zero.

Guarded recovery may set `ready` without replay only if it proves no clear or partial replay began.
Otherwise a successful rebuild is mandatory.

## Deferred evolution

Resumable checkpoints and a staging-table swap are required only when measured event volume,
rebuild duration, or restart cost violates an availability target. That future design must cover
staging lifecycle, events arriving during replay, final catch-up, atomic swap, cleanup, and crash
recovery at every transition.

## Acceptance criteria

T10 - verification and targeted repair (**delivered**):

- Consistent state passes; injected corruption fails without mutation.
- Legitimate delivery lag is classified as in-flight, and overdue delivery, exhaustion, and concrete
  contradiction stay distinct from one another.
- Recent age never hides a canonical outbox mismatch or a receipt/read-row contradiction.
- The reverse passes find orphan receipts, orphan read rows, orphan envelopes, and wrong source
  events without writing.
- Bounded cursor behavior holds across multiple batches with ID gaps, and retained diagnostics stay
  capped even when every examined record fails.
- Timeout and error paths never report a complete clean audit.
- Targeted repair derives one outbox envelope and cannot synthesize business or HTTP truth.
- Repair refuses absent, ambiguous, or contradictory canonical identity, and refuses a missing
  delivery-quiescence acknowledgement, in every case without mutation.
- Repair preserves exhaustion, failure counts, and `projection_failure_generation`, leaving
  `payments:outbox:retry` as the only reactivation path.
- Repair leaves every idempotency receipt, projector receipt, read row, canonical event, and
  unrelated envelope untouched.

T11 - gate, rebuild, and recovery:

- Rebuild waits for already-running consumers before clearing.
- Failed or killed rebuild never exposes partial state as ready.
- Replay uses bounded batches and recreates equal canonical business columns.
- Stale queued duplicates after rebuild are harmless.

## Related

- [Projection and query](projection-and-query.md)
- [Outbox delivery](outbox-delivery.md) — HS-1, the deviation this gate obligation answers.
- [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md) — the relay decision record that defers
  gate acquisition to this task.
- [Resilience test plan](../../testing/payments-resilience.md)
