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

**T11's design is recorded below and in [ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md).**
The control row, the gate primitive, traffic acquisition on all four paths (`POST`, the transactional
`GET`, the relay, and the consumer), and the bounded batch rebuild with its ownership fence are
implemented, per the Slice 1 through Slice 3 evidence below.
Slice 4 adds guarded recovery and takeover with preserved clear evidence.
`503 projection_rebuilding` can be observed during a real rebuild or after an abandoned run leaves the durable gate closed.
Slice 5 adds T10 verify/repair gate participation and the [operator handoff](../../operations/runtime-contract.md#manual-rebuild-and-recovery).

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
| `2` | Audit did not complete, including `rebuild_in_progress` when the gate is closed, timeout, database errors, or invalid invocation/configuration. |
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
adds `complete`, `projection_control_state`, `high_watermark`, `audit_at`, `scanned` counts by pass, `classification_counts`,
`finding_counts`, and the capped `examples` with `examples_truncated`. Pass, check, and example
ordering is deterministic.

Before the audit watermark is captured, `high_watermark` and `audit_at` are null, not invented values.
`projection_control_state` is the control row's `ready` or `rebuilding` value observed in the audit's read-only snapshot, or null if the row is absent or its state could not be read.
The verifier reads it even when the exclusive lock denied gate entry: `ready` can coexist with `rebuild_in_progress` during lock-first acquisition, and never overrides that refusal.
A closed or missing gate produces exit `2`, `complete: false`, reason `rebuild_in_progress`, empty `scanned`/findings/examples, and null watermark/audit timestamp.
It neither scans partial projections nor reports their temporary incompleteness as corruption.
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
fencing design. **T10 adds no provisional gate, fence table, or control row.**

**T11 implements participation, and the acknowledgement stays.** After declaring isolation and bounds, verification and
repair both acquire the shared gate before business-table access inside their own transactions. Verification
under a closed gate produces an **incomplete** report with a `rebuild_in_progress` reason and exit `2`,
never exit `1`: a partially rebuilt projection is not a corruption finding, and reporting it as one
would train an operator to ignore exit `1`. Repair under a closed gate is a refusal with exit `1`, reason `rebuild_in_progress`,
`mutation_state: none`, and no canonical or envelope row locked or changed. `--delivery-quiesced` is **not** removed by the gate: the gate makes gated processes
quiesce, while the acknowledgement exists for the `projection_failure_generation` fence problem above,
which the gate does not solve.

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
| `1` | Expected refusal: closed gate (`rebuild_in_progress`), absent or ambiguous canonical history, identity conflict, or missing quiescence acknowledgement. |
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

**T11's. Slices 1-4 implement the control row, shared gate, traffic integration, rebuild, and recovery.**
T10 verification and repair also acquire the shared gate inside their own transactions.
[ADR 0005](../../adr/0005-payments-projection-gate-and-rebuild-ownership.md) is the decision record; [the roadmap](../../roadmap.md) owns task state.

`payment_projection_controls` stores one row per projection with `ready` or `rebuilding`, owner
token, owner backend pid, start time, `cleared_at`, and timestamps. The row is durable
operator-visible state; advisory locks own mutual exclusion.

- Payments POST, transactional GET, relay, and consumer acquire a transaction-scoped shared lock
  and read the control row on the same explicit connection.
- Rebuild holds a session-level exclusive lock on one pinned connection.
- While rebuilding, Payments HTTP returns retryable `503`; relay/consumer work is deferred without
  consuming projection failure budget or transport budget. Health and unrelated modules remain
  available.
- Losing or reconnecting the pinned session aborts the rebuild. Code never assumes the old lock
  survived.

### Gate key and acquisition

The advisory key is a pair of explicit integer constants owned by one Payments infrastructure type,
one object identifier per projection name, used with the two-integer `pg_advisory_*` form so
`pg_locks` reports the halves in `classid`/`objid` with `objsubid = 2` and evidence can assert exact
values. The durable projection name lives in the control row's primary key; the integer pair is that
name's lock address. A hashed derivation is rejected: it buys extensibility nothing needs and turns a
collision with an unrelated advisory key into a silent event rather than a reviewable one.

### T11 Slice 1 implementation evidence

`ProjectionGateTest` checks the shared gate primitive on real PostgreSQL connections, without an outer test transaction.
An observer reads `pg_locks` to witness the exact key, backend affinity, concurrent shared holders, and release on commit and rollback.
Separate cases cover an exclusive holder, a rebuilding control row, a missing control row, and invocation outside a transaction.
`PaymentsSchemaTest` pins the control table, seeded ready row, timestamp precision, primary key, and named CHECK constraints.
The CHECKs constrain current row shape; preserving `cleared_at` across takeovers remains the responsibility of the later rebuild and recovery implementation.

The exclusive-waiter test observes one granted shared holder and a queued exclusive request before a distinct backend attempts the shared gate.
That attempt returns `ClosedByRebuildLock`; after teardown removes the conflicting request, the shared gate opens again.
This satisfies D2's Slice 1 evidence obligation on the tested stack; it is not a general fairness, throughput, or production availability guarantee.

On 2026-09-03, the Slice 1 `make check` invocation exited `0`: 641 tests and 3528 assertions, with Composer validation, Pint, Rector, PHPStan, and instruction parity passing.
After documentation and instruction-status updates, a separate `make agent-check` invocation also exited `0`.
The review was a separate local, sequential review of the Slice 1 source and tests; it corrected comment claims about CHECK enforcement without changing SQL or runtime behavior.
No `make ci`, `make projection-contract`, or `make image-contract` was run for this checkpoint.
Traffic integration, rebuild ownership, replay, recovery, and T10 command participation are not established by these primitive tests.

### T11 Slice 2 implementation evidence

Gate acquisition landed on all four traffic paths: `ProblemType::ProjectionRebuilding` (503,
`Retry-After: 1`) is raised by a typed `ProjectionRebuilding` application exception from
`RetryingAtomicTransaction` (write path) and `DatabasePaymentQuery` (transactional read); `OutboxRelay`
and `PaymentProjectionConsumer` defer instead, returning `RelayOutcome::GateClosed` and
`ProjectionOutcome::GateDeferred` respectively. `payments:outbox:status` gained a `gate` field read in
the same read-only transaction as its database aggregate. `ProjectionGate` was narrowed from the
concrete `Connection` to `ConnectionInterface` and its control-row read moved from the query builder to
a raw `selectOne()`; both are behind-the-scenes changes with no effect on the gate's observable
behaviour, made to let a Unit test substitute a lightweight connection fake.

Each path's own Integration suite (`OutboxRelayTest`, `PaymentProjectionConsumerTest`,
`PaymentQueryTest`, `InitiationCommitBoundaryTest`) adds a closed-gate section proving: the typed
refusal or deferral outcome; that no row is selected, locked, or written; that every column T7's and
T8's failure/transport budgets own is untouched; that the transaction rolls back or commits cleanly
with nothing left open; and, witnessed from the OBSERVER connection via `pg_locks`, that the shared
try-lock is genuinely granted on the path's own backend while the control row is what closes the gate
- proving D3's "both checks are required" on each path rather than only on the primitive.
`QueryPaymentTest` and `InitiatePaymentWriteTest` add the HTTP-level 503 contract. Every closed-gate
test reopens the gate and re-runs the same call as its non-vacuity control, and every suite's
`afterEach` unconditionally resets the control row so a failed assertion cannot leak `rebuilding` into
a later test.

On 2026-09-03, `make check` exited `0`: 655 tests and 3670 assertions, with Composer validation, Pint,
Rector, PHPStan, and instruction parity passing. `make projection-contract` then exited `0` in 120s.
A separate local review of the Slice 2 diff found no correctness issue.
Traffic paths defer correctly with the gate closed by the durable row; no exclusive holder, no bounded
rebuild wait, and no lock-manager interaction was exercised beyond what Slice 1 already measured. The
bounded batch rebuild, its ownership fence, recovery, and T10 verify/repair gate participation (D10)
were outside the Slice 2 checkpoint.

### Traffic acquisition

**Traffic never waits on the gate.** All four traffic paths use the non-blocking
`pg_try_advisory_xact_lock_shared`, so a refusal is an immediate, definite answer and no request,
relay pass, or delivery can be turned into a timeout by a rebuild. Only the rebuild's exclusive
acquisition blocks, bounded by `lock_timeout`.

### Both checks are required, and they fail closed

A gate check is the lock **and** the control row, read in that order inside one transaction on one
connection, after the path's existing `SET LOCAL` bounds. Either signal alone closes the gate, and
neither is redundant:

- **The lock alone misses a rebuild whose session is gone.** PostgreSQL releases a session-level
  advisory lock when the backend goes away, so the lock becomes free while the read model is still
  half-cleared. The durable row is what closes the gate then.
- **The row alone misses the window before a rebuild has written it**, and cannot serialize
  concurrent work at all.

A **missing** control row is treated as closed, never as `ready`. A projection whose control state
cannot be read is not a projection anyone should be reading. The forward migration seeds exactly one
`ready` row for `payment_details`, so an absent row means damage.

### Deferral is not a failure

A closed gate produces a distinct deferral outcome on the relay and on the consumer, and it is
reported separately from a drained backlog so an operator never reads a closed gate as an empty
queue.

The consumer's deferral **raises no exception**. `ProjectPaymentEvent` has no `try`/`catch` and the
`payments-projection` connection allows one attempt, so an exception raised for a deferral would
retire the message into `failed_jobs` rather than deferring it. The deferred delivery locks no
envelope, so `projection_failure_count`, `projection_failure_generation`, `exhausted_at`,
`available_at` and every transport column are untouched by construction rather than by rule, which is
what makes "gate deferral consumes no budget" a structural property. The relay's deferral selects no
row for the same reason.

Deferred work returns through the mechanism T7 already owns: the outbox row still carries a null
`projection_completed_at`, its reconciliation grace expires, and the relay republishes once the gate
reopens. No second redelivery owner is introduced. The accepted consequence is that jobs published
before a rebuild are consumed and discarded at the queue's drain rate while it runs; the durable
outbox is the source of truth and Redis is never authoritative.

### T7's reciprocal obligation: discharged by T11 Slice 2

T7 shipped `OutboxRelay::relayOne()` — one row, one short transaction, `SKIP LOCKED`, publish before
commit — without acquiring any shared lock or reading `payment_projection_controls`, because that
table, its advisory-key derivation, and this gate's deferral semantics were T11's design, not T7's.
Recorded as HS-1 in [outbox delivery](outbox-delivery.md#hs-1-discharged-by-t11-slice-2)
and in [ADR 0004](../../adr/0004-lock-through-push-outbox-relay.md).

**T11 Slice 2 discharges it.** Gate acquisition is the first statement inside `relayOne()`, before
eligibility selection, on the same injected `Connection` the relay already held — not a second
connection, and not a call wrapped around the existing method, so the shared lock and the row lock it
protects report the same backend pid the way Q6's evidence requires. A closed gate returns
`RelayOutcome::GateClosed`, distinct from `NothingEligible`, with no row selected and nothing written.

### T8's reciprocal obligation: discharged by T11 Slice 2

T8's consumer shipped with **no** gate: `PaymentProjectionConsumer::project()` opens its transaction
by locking and loading the `payment_outbox` envelope by `event_id`, as
[projection and query](projection-and-query.md) records - for the same reason T7 took none: the
control row, its advisory-key derivation, and the deferral semantics were T11's design.

**T11 Slice 2 discharges it.** Gate acquisition is prepended to that same consumer transaction,
before the envelope lock, on the same injected `Connection` the consumer already held - not a second
connection and not a wrapper around the transaction. A closed gate returns
`ProjectionOutcome::GateDeferred` and **raises no exception** (`ProjectPaymentEvent` has `tries = 1`
and no `try`/`catch`), locking no envelope row, so `projection_failure_count`,
`projection_failure_generation`, `exhausted_at`, `available_at`, and every transport column are
untouched by construction. Deferred work consumes no projection failure budget and no transport
budget: a deferral is neither a confirmed handler failure nor a transport rejection.

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
fenced token, or a refusal to reconnect at all. T11 made that choice; T4 only proves the naive
inference is wrong.

## Rebuild algorithm

**Implemented in T11 Slice 3.** Targeted repair reconstructs one outbox envelope and never touches
the read model, so it was never a substitute for this rebuild.

1. Acquire the session-level exclusive advisory lock on one pinned connection, blocking within a
   bounded `lock_timeout`, which also waits for prior shared transactions to finish.
2. In one short transaction, take the control row `FOR UPDATE`, decide, and mark the projection
   `rebuilding` with a fresh owner token, preserving `cleared_at` per the rule below.
3. Capture the maximum global stored-event ID.
4. Set `cleared_at` and clear only `PaymentDetails` and its projector receipts, in one transaction.
5. Replay Payments events through the watermark in fixed configurable batches, deriving each read row
   from canonical `stored_events` through the same deterministic application service the consumer
   uses.
6. Recreate projector receipts and commit each batch while the gate remains closed.
7. Mark `ready` only after complete success, clearing `cleared_at` in that same transaction, and
   release the lock.

**Steps 1 and 2 are deliberately in this order, and it is not the order an earlier draft of this
document specified.** Marking before locking cannot be made atomic against a concurrent rebuild by
marking alone: a second run would overwrite the first run's owner token, and the ownership fence would
then abort the winner rather than the loser. A second run that finds `rebuilding` also has no way to
tell a live owner from a lost one, because a lost owner leaves exactly the row a live one does. The
lock is the only thing on this stack that adds information the durable row cannot, so ownership is
decided after it is held, not before. The intent the original order expressed is unchanged: the
projection is durably marked before anything is cleared, and traffic is refused for the whole window,
because the exclusive lock closes the gate from step 1.

Lock timeout aborts before clearing data and writes no control-row column at all. Handled error or
process death leaves the durable row as `rebuilding`, even though PostgreSQL releases the session
lock. An authorized next run reacquires the exclusive lock, clears partial derived state, and restarts
from zero.

### What acquiring the exclusive lock proves, and what it does not

It proves exactly one thing: **no other session currently holds the gate.** It does **not** prove that
a previous owner's process has exited. A rebuild process can lose its database session and keep
running, with a healthy process and a mid-flight replay loop, when a backend is terminated by an
administrator, a network partition, a connection-pooler restart, or a server restart.

The safety property is therefore not that a predecessor is dead. It is that **a predecessor is
permanently write-fenced**: it lost the lock, and the fence below refuses every write it can still
attempt. A takeover is safe because the predecessor cannot write, not because it cannot run.

### Ownership fence

Ownership is re-verified on the pinned connection at three points, each one where being wrong is
destructive or visible to clients: immediately before the destructive clear, at the start of every
batch transaction, and inside the transaction that sets `ready` and reopens the gate.

Each check asserts both of:

- the control row, read `FOR UPDATE`, still reads `rebuilding` under **this run's owner token**, which
  catches a takeover; and
- **this backend still holds the exclusive gate lock**, read from `pg_locks` filtered by the advisory
  locktype, the gate key, exclusive mode, granted, the current database OID, and the current backend
  pid, which catches a lost session that the framework silently reconnected.

Any failure aborts the run, writing nothing further and never setting `ready`.

**Both are load-bearing, and the second is what a token-only fence misses.** If a session is lost and
no takeover has happened yet, the owner token still matches the running process's own token and the
state still reads `rebuilding`, so a token-only fence passes and that process keeps writing on a
session holding no lock while another run is free to take the gate. Ordering does not rescue it: if a
successor takes over first, both assertions fail, but if the fenced run checks first, only the lock
assertion catches it.

Lock possession is asserted directly rather than by comparing a stored backend pid, because a pid
comparison is a proxy for the same question and is defeated by pid reuse. The stored owner pid remains
operator diagnostics naming which backend a stuck rebuild belonged to, and carries no safety weight.
Reading `pg_locks` for the current backend is a runtime self-check, not the affinity *evidence* T4
takes from an observer connection; tests for this fence still witness from a third connection.

### Commit uncertainty during a rebuild

A lost response to `COMMIT` is **not** a rollback, here as everywhere else in Payments. A batch,
a clear, or a state transition whose commit outcome is unknown may be durable, and the rebuild reports
an unknown outcome rather than a clean abort, exactly as targeted repair reports `mutation_state`.

Recovery does not depend on resolving that uncertainty, because the clear and its `cleared_at` marker
commit in **one** transaction: either both landed or neither did. The next authorized run reads the
durable row rather than trusting any claim the previous run made about its own last commit.

### Guarded recovery and the meaning of `cleared_at`

`cleared_at` does not record when a particular run cleared. It records that **the projection has been
destructively cleared and no replay has completed since.** Reading it as a per-run timestamp is what
would licence a new run to reset it.

It has exactly two writers: it is set immediately before the destructive clear, in that transaction,
and only when still null, so it records the first clear since the last completed rebuild; and it is
set back to null only by the transition to `ready` after a complete successful replay, in that same
transaction.

**Every other path leaves it untouched.** A takeover writes state, owner token, owner pid and start
time and does not touch it, because a new owner is not new evidence. A run that marks `rebuilding` and
then fails before its own clear leaves an inherited value intact, having proved nothing about a
predecessor's damage. No abort path writes it. Stated as one invariant: once `cleared_at` is non-null
it stays non-null across any number of takeovers, aborts, and re-invocations, until a replay completes.

Guarded recovery may set `ready` without replay only when the durable row proves no clear was
committed, which is `state = 'rebuilding'` with `cleared_at` null. Otherwise a successful rebuild is
mandatory, even if the read model happens to look complete.

### What a rebuild clears, recreates, and never touches

| Table | Rebuild behaviour |
|---|---|
| `payment_details` | Cleared and recreated from canonical history. |
| `payment_projector_receipts` | Cleared and recreated, for the named `payment_details` projector only. |
| `payment_outbox.projection_completed_at` | Reconciled: set where a recreated receipt exists and the marker is null; otherwise left alone. |
| `payment_outbox`, every other column | Untouched. Transport history, failure counts, generation and exhaustion are preserved. |
| `payment_outbox` rows | **Never created and never deleted.** Envelope reconstruction stays `payments:outbox:repair`, one envelope at a time. This answers the question [outbox delivery](outbox-delivery.md#envelope-and-active-index) left to this task. |
| `stored_events`, `snapshots` | Never touched, and never replayed through Spatie, the aggregate, or the outbox writer. |
| `payment_idempotency_receipts` | Never touched. It is a non-reconstructable HTTP contract. |

Marker reconciliation is the one place a rebuild writes to a table it does not own, and it is
deliberate: without it a **successful** rebuild would be immediately followed by verification
reporting the recreated receipt as a receipt without its marker, training an operator to ignore real
findings.

Replay derives read rows from canonical `stored_events`, never from `payment_outbox`. The outbox is
derived state this document exists to detect corruption in, so rebuilding the read model from it would
launder that corruption into the read model. `initiated_at` continues to come from event `occurred_at`
at durable precision, so a rebuild reproduces the rows the consumer produced.

Stale queued duplicates delivered after a successful rebuild are harmless through recreated receipts
and are not actively drained; no queue is flushed.

### T11 Slice 3 implementation evidence

`payments:projections:rebuild --confirm-rebuild` is implemented as `DatabaseProjectionRebuild`, one
pinned `Connection` with its reconnector replaced for the run's duration (F3), behind the
`ProjectionRebuild` port. Phase 1 acquires the session-level exclusive lock with a bounded, blocking
`lock_timeout`, reset to `default` immediately after acquisition. Phase 2 claims ownership in a short
`FOR UPDATE` transaction that never names `cleared_at`. The clear transaction captures the watermark,
sets `cleared_at = coalesce(cleared_at, now())`, and deletes `payment_details` and the named projector's
receipts, all three in one commit. Replay walks canonical `stored_events` in keyset batches through
`PaymentInitiatedEnvelope::fromStoredEventRow()` - the same decoder T10's verifier and repair already
share - inserting the receipt, the read row, and reconciling the completion marker per event. The ready
transition nulls `cleared_at` and reopens the gate. The ownership fence (`assertOwnership()`) runs at
all three checkpoints, asserting the control row's token under `FOR UPDATE` and this backend's own
`pg_locks` possession of the exclusive lock; a failure at either check aborts the run without a further
write and is never ambiguous, because it is raised from a plain read before any commit is attempted.
Every transactional step classifies a lost `COMMIT` response as `commit_outcome_unknown` rather than
inferring a rollback, matching the relay's, the consumer's, and targeted repair's own boundary.

**Both fence halves are independently proven**, not only in combination. A dedicated test releases the
pinned session's own exclusive lock directly (`pg_advisory_unlock`, on the same live, healthy
connection - never a kill and never a reconnect) while state and token are left untouched, so the row
half of the fence would pass on its own; the full fence still refuses, and a non-vacuous control asks
the identical row-only question at the identical moment and confirms it would have answered "still
owned". This isolates the lock-possession check from both a takeover and from F3's reconnector defence,
which the test also asserts was never exercised.

Everything from the intent log onward runs under one guaranteed release: `runUnderExclusiveLock()`
wraps `runClaimedRebuild()` in a `finally` that releases the exclusive lock on every exit, including a
broken logger on the intent record - the first write attempted after the lock is confirmed held, before
`claimOwnership()` ever runs, and reported as `intent_audit_failed` (matching `RepairReason`'s own case
exactly) rather than a database error. A broken logger on the *completion* record, staged after
`transitionToReady()` has already committed, is reported as `audit_failed` carrying the real final
`high_watermark`/`events_replayed`/`batches_committed` - a failure exit, deliberately, because a broken
audit sink means the run cannot vouch for its own record, but never `database_error`, which would
wrongly suggest the projection stayed `rebuilding`.

Reconnection suppression is scoped to the run, not to the connection's remaining lifetime:
`rebuild()`'s own outer `finally` restores the reconnector captured before installing the throwing one,
on every exit - refused before the lock was ever acquired, completed, or aborted mid-replay alike -
which is what "for the duration of this run" actually promises for a connection object every other
Payments adapter in the same process may still need afterwards.

Token minting and the backend-pid read that precede the intent log are caught the same way, and
separately from it: a connection lost the instant after the exclusive lock is acquired - a realistic
PostgreSQL event, not only a mid-transaction one - is reported as `database_error` rather than folded
into `intent_audit_failed`, and neither classification touches `runStep()`'s own commit-uncertainty
logic, because nothing here has opened a transaction yet.

`ProjectionRebuildTest` proves, against real PostgreSQL and without `RefreshDatabase`: phase 1 genuinely
blocks on a traffic-shaped shared holder on a second connection and refuses with `lock_timeout` writing
no control-row column, then succeeds once the holder releases; replay walks more than one keyset batch
across an ID gap left by a foreign canonical event and reproduces the exact columns
`PaymentProjectionConsumer` would have written, including `initiated_at` at microsecond precision;
receipts are recreated for exactly the named projector; the completion marker is reconciled with every
other `payment_outbox` column and row, `stored_events`, and `payment_idempotency_receipts` left
untouched; a stale duplicate delivered after a successful rebuild takes the consumer's own duplicate
path harmlessly; the lock half of the fence is caught in isolation as described above; a takeover
simulated between two replay batches is caught by the token check at checkpoint 2 and again immediately
before the ready transition at checkpoint 3, in both cases with the run reporting its partial progress
and the control row never reaching `ready`; a real `pg_terminate_backend` against the pinned
connection's own backend between two batches, confirmed released through a `pg_locks` barrier rather
than a sleep, aborts the run with no further write; a connection lost immediately after acquiring the
exclusive lock, injected deterministically on the `pg_backend_pid()` read itself, reports
`database_error` with the control row proven untouched; a failed intent log and a failed completion log
are each reproduced, with the lock's release witnessed directly from `pg_locks` on all three; and the
reconnector's restoration is proven on all three exit families - refused, completed, and aborted.

Guarded recovery, takeover's own ownership-claim branch, and the chained clear/kill/takeover invariant
across multiple runs are Slice 4's. `make check` exited `0` on 2026-09-03: 671 tests, 3801 assertions.

### T11 Slice 4 implementation evidence

After exclusive acquisition, `claimOwnership()` reads the previous state and `cleared_at` under `FOR UPDATE`, writes fresh ownership fields, and returns the inherited state without changing the marker.
Only a previously `rebuilding` row with a null marker selects guarded recovery.
That path calls the existing fenced `transitionToReady()` without clearing, reading canonical history, or replaying events.
A fresh start or an inherited non-null marker takes the full clear-and-replay path from cursor zero.
No fence checkpoint, migration, dependency, or CLI recovery option was added.

The command's JSON gains the additive boolean `guarded_recovery` field.
It reports selection of the recovery path, not success: it remains true when that path loses ownership or its completion audit fails, and is false before any recovery decision.
A completed guarded recovery reports `high_watermark: null`, `events_replayed: 0`, and `batches_committed: 0`.
An `audit_failed` result after the ready commit remains exit `2`, carries those same progress fields, and does not imply that the projection stayed `rebuilding`.
Existing outcome, reason, exit-code, and commit-uncertainty mappings are unchanged.

Eight additional `ProjectionRebuildTest` cases prove these contracts against real PostgreSQL:

- Killing the backend after the claim commit leaves a null marker; the next authorized attempt returns to ready without changing the existing read row, projector receipts, or outbox columns.
- A non-null marker requires replay even when the existing projection looks complete.
- A negative control erases a simulated abandoned run's marker and observes guarded recovery incorrectly leaving a missing projection under `ready`; this mutates fixture state, not production code.
- A takeover killed before its own clear changes ownership fields but preserves the inherited timestamp exactly; a third attempt still performs full replay.
- A chain killed after one committed batch, then after the next attempt's clear, preserves the timestamp across both takeovers, discards partial rows, and replays from the first canonical event.
  Observer reads after every commit of the final attempt see the original marker until the ready commit clears it.
- A live exclusive owner causes a bounded `lock_timeout` refusal before claim; its entire control row remains unchanged and its lock remains held.
- Guarded recovery that loses its own exclusive lock is refused by the existing ready fence, with `ownership_lost` and the durable gate still closed.
- Failure of the completion audit preserves the guarded-recovery result and its actual ready state while releasing the lock.

Backend kills target only the captured test backend, with the current database OID, two-integer gate key, and lock namespace checked from the observer connection.
The tests wait for observed lock release, then verify that the durable row alone still closes the shared gate.
Repeated command attempts resolve a fresh adapter after `DB::purge()` so Artisan does not retain a command attached to the killed connection.
This is in-process database-session failure evidence; the barrier-coordinated OS-process kill matrix was delivered by T12.

On 2026-09-03, `make check` exited `0`: **679 tests, 3907 assertions**, including Composer validation, Pint, Rector, PHPStan, and instruction parity.
After recording this evidence, a separate `make agent-check` also exited `0`.
A separate local, sequential review covered the actual runtime, test, and documentation diff.
The initial test run reproduced three failures caused by the stale Artisan command instance; the final run passes those same scenarios.
No `make ci`, `make projection-contract`, or `make image-contract` was run for this checkpoint.
Slice 5's T10 command participation and operator handoff were still pending at this checkpoint.

### T11 implementation evidence

The table maps the complete T11 contract to named tests; suite names refer to `Modules/Payments/tests/Integration/` unless marked Feature.
Earlier slice checkpoints retain their original validation counts and scope.

| Contract | Owning test and scope |
|---|---|
| Rebuild waits for in-flight consumers before clear; timeout changes nothing | `ProjectionRebuildTest`: `waits for a traffic-shaped shared holder, refuses on lock timeout, and clears nothing`; `PaymentProjectionConsumerTest`: `holds the gate lock on its own connection while a delivery is deferred by the control row`. These compose the shared-holder protocol; they are not an OS-process scheduling matrix. |
| Failed or killed rebuild never exposes partial state as ready | `ProjectionRebuildTest`: `preserves cleared_at across a chain of clear, kill, takeover, kill, takeover, until a replay completes`, including observer evidence of closed traffic after lock release. |
| Bounded replay recreates equal canonical business columns | `ProjectionRebuildTest`: `replays bounded batches across an ID gap and reproduces the columns the consumer would have written`. |
| Stale queued duplicates remain harmless | `ProjectionRebuildTest`: `takes the duplicate path harmlessly when a stale delivery arrives after a successful rebuild`. |
| Ownership requires the token and actual session lock | `ProjectionRebuildTest`: `the lock half of the fence catches a live, un-taken-over session that has lost the lock`; `checkpoint 2: a pinned session terminated between batches aborts the run with no further write`; `checkpoint 3: ownership lost before the ready transition never reopens the gate`. |
| Takeover preserves inherited clear evidence, including early failure | `ProjectionRebuildTest`: `a takeover that fails before its own clear leaves the inherited cleared_at intact, and a third run is still refused the shortcut`; `refuses a second rebuild against a live owner, never reaching phase 2, and the live owners token survives`. |
| Guarded recovery is restricted to an abandoned pre-clear run | `ProjectionRebuildTest`: `guarded recovery sets ready without replay when a takeover finds cleared_at still null`; `never takes the guarded-recovery shortcut when cleared_at is non-null, even if the read model looks complete`; `guarded recovery keeps the ready fence and cannot reopen traffic after losing its lock`. |
| Uncertain commits preserve durable recovery evidence | `ProjectionRebuildTest`: `reports an uncertain commit without discarding durable recovery evidence`, at claim, clear, batch, ready, and guarded-recovery ready commits. Injection occurs after a real commit; it establishes the committed side of uncertainty, not a network acknowledgement failure. |
| Rebuild ownership stays within derived state and marker reconciliation | `ProjectionRebuildTest`: `reconciles the completion marker and touches no other outbox column, row, or unrelated table`. |
| POST/GET refuse and relay/consumer defer without charging failure budgets | `InitiationCommitBoundaryTest`: `holds the gate lock on its own connection while initiation is refused by the control row`; Feature `QueryPaymentTest`: `answers 503 projection_rebuilding with Retry-After: 1 while the gate is closed`; `OutboxRelayTest`: `reports a closed gate without selecting a row, publishing or writing anything`; `PaymentProjectionConsumerTest`: `leaves every column the recorder and the relay own untouched by a deferral`. |
| Deferred work returns through existing reconciliation | `OutboxRelayTest`: `republishes the deferred event once the gate reopens, on the ordinary reconciliation path`; `PaymentProjectionConsumerTest`: `defers a delivery without locking the envelope, throwing or writing anything`. |
| Verify/repair refuse a closed gate before business-table access | `PaymentProjectionVerifierTest`: `reports a closed maintenance gate as incomplete before scanning and preserves the observed control state`; `PaymentOutboxRepairTest`: `refuses a closed maintenance gate before canonical locking without changing any durable state`. Each covers exclusive lock, abandoned row, and missing row, then a successful reopening control. |
| Maintenance locks share the audit/write backend and last through the transaction | `PaymentProjectionVerifierTest`: `holds the shared gate on the audit snapshot backend until verification finishes`; `PaymentOutboxRepairTest`: `holds the shared gate on the repair write backend until its commit`. Observer locks and competing exclusive attempts prove affinity and lifetime. |
| Corruption is fixed by rebuild and maintenance observes real rebuild exclusion | `ProjectionRebuildTest`: `gates verification and repair during a real rebuild and verifies the rebuilt canonical state`. |
| Audit failures and connection lifecycle retain truthful outcomes | `ProjectionRebuildTest`: `never begins the mutation when operator intent cannot be recorded, and still releases the lock`; `reports completed guarded recovery accurately when its completion audit fails`; `restores the original reconnector after an aborted run`. Additional exit-family cases are recorded in Slice 3 evidence. |

Slice 5 adds `rebuild_in_progress` before the verifier watermark/scans and before repair's canonical lock.
The verifier reports the observed control state separately and keeps its `REPEATABLE READ, READ ONLY` snapshot; repair retains `READ COMMITTED` and the explicit delivery-quiescence acknowledgement.
Both hold the shared transaction lock through their work, so rebuild cannot clear state between a maintenance check and its protected reads or writes.
The [operator procedure](../../operations/runtime-contract.md#manual-rebuild-and-recovery) covers result interpretation, lock/control inspection, replay versus guarded recovery, and unresolved commit outcomes.

These are bounded local fixtures and deterministic database-session/failure injections.
They establish no production-scale SLA, throughput figure, zero-downtime guarantee, multi-process kill matrix, or rehearsal against a real incident.
The barrier-coordinated OS-process matrix was delivered by T12; online staging swaps and resumable replay remain deferred below.

On 2026-09-07, the final continuous `make ci` run exited `0`: **693 tests, 4116 assertions**;
the development-database marker row survived `make check` intact, the projection contract passed in
127 seconds, and the API and web CI images both built successfully.
This validates the current Slice 5 source, tests, instructions, and canonical documentation in one
repository-defined run.

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

T11 - gate, rebuild, and recovery (**implemented; owning tests in the table above**):

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
