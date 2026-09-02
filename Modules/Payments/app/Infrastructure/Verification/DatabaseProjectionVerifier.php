<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Payments\Application\Verification\ProjectionVerifier;
use Modules\Payments\Application\Verification\VerificationClassification;
use Modules\Payments\Application\Verification\VerificationExample;
use Modules\Payments\Application\Verification\VerificationFinding;
use Modules\Payments\Application\Verification\VerificationReport;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Override;
use stdClass;
use Throwable;

/**
 * The canonical forward pass, inside one snapshot, writing nothing.
 *
 *     BEGIN
 *     SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY
 *     SET LOCAL statement_timeout / transaction_timeout
 *     select max(id), now()                       <- watermark and audit_at
 *     loop: one keyset batch of canonical Payments events, then its derived rows
 *     COMMIT
 *
 * REPEATABLE READ IS OWNED EXPLICITLY, NOT INHERITED. Under READ COMMITTED every
 * statement takes its own snapshot, so the watermark, the canonical batches and
 * the derived lookups would each see a different committed state and the report
 * would describe a database that never existed at any single instant. `READ ONLY`
 * on the same statement is what makes non-mutation a PostgreSQL guarantee rather
 * than a code review: a write attempted in here is refused as 25006.
 *
 * THE WATERMARK IS THE MAXIMUM VISIBLE id, NOT THE SEQUENCE'S ALLOCATED VALUE,
 * and the global id is NOT a commit-order fence. A transaction that allocated a
 * lower id can still commit after this snapshot opened, so this reports the rows
 * VISIBLE in one snapshot - not a permanent claim that every id below the
 * watermark is durable. Late commits belong to the next run, and every run is a
 * fresh audit rather than a resumable checkpoint.
 *
 * KEYSET, NEVER OFFSET, AND NO SET OF VISITED IDS. The cursor is the last id of
 * the previous batch, so memory is one batch regardless of how much history
 * exists. Counts accumulate; retained examples stop at a cap and say so.
 *
 * WHAT THIS PASS DOES NOT DO. It walks canonical history FORWARD, so it can only
 * find derived state that some canonical event points at. A projector receipt, a
 * read row or an envelope that no stored event refers to is invisible from here
 * by construction - that is the reverse-scan work, and `scanned` names only the
 * pass that actually ran so no reader can mistake one for the other.
 *
 * IT NEVER REHYDRATES PaymentAggregate, dispatches an event, or invokes the
 * consumer, the relay or the outbox writer. An audit that reused the live
 * projection path to decide what SHOULD be there would be asking the suspect.
 *
 * THE ROW SHAPES ARE DECLARED RATHER THAN CAST FROM mixed. The query builder hands
 * back stdClass, whose every property is mixed at level 10, and pgsql is free to
 * return a bigint as either int or string depending on the driver's emulation
 * setting. Naming the shapes once here is what lets the comparisons below be
 * ordinary typed code instead of a suppression.
 *
 * @phpstan-type CanonicalRow object{id: int|string, aggregate_uuid: string, aggregate_version: int|string, event_version: int|string, event_class: string, event_properties: string|null}
 * @phpstan-type DetailsRow object{payment_id: string, source_event_id: string, merchant_id: int|string, amount_minor: int|string, currency: string, status: string, aggregate_version: int|string, initiated_at: string}
 */
final class DatabaseProjectionVerifier implements ProjectionVerifier
{
    /** Every Payments alias, so an alias this audit cannot decode is still SEEN. */
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    /**
     * The keyset origin for a uuid-keyed reverse pass.
     *
     * SAFE AS A STRICT LOWER BOUND because every identifier in these tables is a
     * UUIDv7 minted by this application, and the nil uuid is not producible by
     * that generator. A row keyed by it would be skipped, which is why it is
     * stated here rather than left as an unexamined `> '000...'`.
     */
    private const string NIL_UUID = '00000000-0000-0000-0000-000000000000';

    /** `read_only_sql_transaction` - a write attempted inside the audit snapshot. */
    private const string READ_ONLY_SQLSTATE = '25006';

    /** `query_canceled` - statement_timeout fired. */
    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    public const string REASON_WORK_BUDGET = 'work_budget_exhausted';

    public const string REASON_PAYLOAD_TOO_LARGE = 'payload_exceeds_inspection_limit';

    public const string REASON_STATEMENT_TIMEOUT = 'statement_timeout';

    public const string REASON_READ_ONLY_VIOLATION = 'read_only_violation';

    public const string REASON_DATABASE_ERROR = 'database_error';

    /** @var array<string, int> */
    private array $classificationCounts;

    /** @var array<string, int> */
    private array $findingCounts = [];

    /** @var list<VerificationExample> */
    private array $examples = [];

    private bool $examplesTruncated = false;

    /**
     * Rows examined per pass, keyed in report order.
     *
     * A KEY APPEARS ONLY WHEN ITS PASS STARTS, which is what gives
     * VerificationReport::completed() something real to refuse: a pass lost to a
     * future early return leaves its key absent and the report cannot be built as
     * complete. A pass that started and aborted keeps its partial count, because
     * an incomplete run should still say how far it got.
     *
     * @var array<string, int>
     */
    private array $scanned = [];

    private int $highWatermark = 0;

    private ?string $auditAt = null;

    private ?CarbonImmutable $auditInstant = null;

    /**
     * @param  Closure(): int  $clock  monotonic nanoseconds, for the work budget
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly VerificationConfiguration $config,
        private readonly Closure $clock,
    ) {
        $this->classificationCounts = VerificationClassification::zeroed();
    }

    #[Override]
    public function verify(): VerificationReport
    {
        $this->reset();

        $deadline = ($this->clock)() + $this->config->workBudgetNanoseconds();
        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            // FIRST, AND IN ONE STATEMENT. SET TRANSACTION only applies before any
            // query has run in the transaction, and pairing the isolation level
            // with READ ONLY here means there is no window in which the audit is
            // open but still able to write.
            $this->connection->statement('set transaction isolation level repeatable read, read only');
            $this->connection->statement($this->config->statementTimeoutStatement());
            $this->connection->statement($this->config->transactionTimeoutStatement());

            $this->captureSnapshot();

            // FOUR PASSES, ONE SNAPSHOT, IN THIS ORDER. Every one of them reads the
            // state established above, so a record deleted between the canonical
            // walk and a reverse walk cannot make one pass contradict another. The
            // order is for readability only: none of them depends on another's
            // result, which is precisely why a reverse pass can find what the
            // forward walk structurally cannot.
            foreach ([
                $this->scanCanonicalEvents(...),
                $this->findDuplicateCanonicalEventIds(...),
                $this->scanProjectorReceipts(...),
                $this->scanReadRows(...),
                $this->scanOutboxEnvelopes(...),
            ] as $pass) {
                $reason = $pass($deadline);

                if ($reason !== null) {
                    // The snapshot is released rather than committed: nothing was
                    // written, and holding it open while the caller formats a
                    // partial report would keep a REPEATABLE READ transaction alive
                    // for no reason.
                    $this->rollBackQuietly();

                    return $this->partial($reason);
                }
            }

            $this->connection->commit();

            return VerificationReport::completed(
                highWatermark: $this->highWatermark,
                auditAt: (string) $this->auditAt,
                scanned: $this->scanned,
                classificationCounts: $this->classificationCounts,
                findingCounts: $this->sortedFindingCounts(),
                examples: $this->examples,
                examplesTruncated: $this->examplesTruncated,
            );
        } catch (Throwable $failure) {
            if ($transactionStarted) {
                $this->rollBackQuietly();
            }

            // THE FINDINGS ALREADY OBSERVED SURVIVE, AND THE RUN IS STILL
            // INCOMPLETE. Discarding them would throw away the only evidence the
            // audit produced; presenting them as a finished audit would claim
            // history the snapshot never reached.
            return $this->partial($this->safeReason($failure));
        }
    }

    /**
     * The watermark and the audit timestamp, read once inside the snapshot.
     *
     * `now()` IS transaction_timestamp(), fixed for the whole transaction, which
     * is exactly the property the age comparison needs: every event in this run is
     * aged against one instant rather than against a clock that moves between
     * batches.
     */
    private function captureSnapshot(): void
    {
        $row = $this->connection->selectOne(
            'select coalesce(max(id), 0) as high_watermark, now() as audit_at from stored_events'
        );

        // TYPES, NOT VALIDATION. An aggregate with no GROUP BY returns exactly one
        // row, so this branch exists to make the conversions below honest rather
        // than suppressed.
        if (! $row instanceof stdClass || ! is_numeric($row->high_watermark) || ! is_string($row->audit_at)) {
            throw new VerificationSnapshotUnavailable(
                'The stored_events watermark query returned an unexpected shape; refusing to audit.'
            );
        }

        $this->highWatermark = (int) $row->high_watermark;
        $this->auditInstant = CarbonImmutable::parse($row->audit_at)->utc();
        $this->auditAt = $this->auditInstant->format(PaymentInitiated::OCCURRED_AT_FORMAT);
    }

    /**
     * Walk canonical Payments events through the watermark, in keyset batches.
     *
     * Returns null when the pass completed, or the safe reason that stopped it.
     *
     * THE BUDGET IS CHECKED BEFORE EACH NEXT BATCH, never inside one. A batch
     * already begun always finishes being evaluated, so a partial report never
     * describes half an event.
     */
    private function scanCanonicalEvents(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_CANONICAL_EVENTS);

        $cursor = 0;

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            $batch = $this->canonicalBatch($cursor);

            if ($batch === []) {
                return null;
            }

            $reason = $this->evaluateBatch($batch);

            if ($reason !== null) {
                return $reason;
            }

            $cursor = (int) $batch[count($batch) - 1]->id;

            if (count($batch) < $this->config->batchSize) {
                return null;
            }
        }
    }

    /**
     * One keyset batch of canonical Payments rows.
     *
     * `like 'payments.%'` RATHER THAN THE ONE DECODABLE ALIAS. Scoping to the exact
     * alias would make a Payments event this audit cannot decode invisible instead
     * of reported, which is the difference between "no corruption" and "no
     * corruption I looked for". Other bounded contexts stay outside the audit.
     *
     * THE PAYLOAD IS SIZED IN SQL AND WITHHELD IF IT IS OVER THE CAP, rather than
     * fetched and then measured. Fetching first would mean the cap protects
     * nothing: the bytes are already in the process by the time it is consulted.
     *
     * @return list<CanonicalRow>
     */
    private function canonicalBatch(int $cursor): array
    {
        /** @var list<CanonicalRow> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
            select id,
                   aggregate_uuid,
                   aggregate_version,
                   event_version,
                   event_class,
                   case
                       when octet_length(event_properties::text) <= ?
                       then event_properties::text
                   end as event_properties
              from stored_events
             where event_class like ?
               and id > ?
               and id <= ?
             order by id
             limit ?
            SQL,
            [
                $this->config->maxPayloadBytes,
                self::PAYMENTS_ALIAS_PREFIX.'%',
                $cursor,
                $this->highWatermark,
                $this->config->batchSize,
            ],
        );

        return $rows;
    }

    /**
     * Evaluate one batch, or report the reason the audit cannot continue.
     *
     * @param  list<CanonicalRow>  $batch
     */
    private function evaluateBatch(array $batch): ?string
    {
        $decoded = [];
        $payloads = [];
        $eventIds = [];
        $paymentIds = [];

        foreach ($batch as $row) {
            $storedEventId = (int) $row->id;
            $this->scanned[VerificationReport::PASS_CANONICAL_EVENTS]++;

            // A WITHHELD PAYLOAD STOPS THE AUDIT RATHER THAN BEING SKIPPED. Its
            // contents were never inspected, so it can be declared neither
            // consistent nor corrupt, and an audit that quietly stepped over it
            // would report a complete clean pass over history it did not read.
            if ($row->event_properties === null) {
                return self::REASON_PAYLOAD_TOO_LARGE;
            }

            try {
                // NAMED EXPLICITLY, NOT `(array) $row`. The decoder reads five keys
                // and the batch selects six; spreading the whole row would hand it
                // a column it does not read and would hide a rename behind a cast.
                $decoded[$storedEventId] = PaymentInitiatedEnvelope::fromStoredEventRow([
                    'aggregate_uuid' => $row->aggregate_uuid,
                    'aggregate_version' => $row->aggregate_version,
                    'event_version' => $row->event_version,
                    'event_class' => $row->event_class,
                    'event_properties' => $row->event_properties,
                ]);
                $payloads[$storedEventId] = $row->event_properties;
            } catch (ProjectionEnvelopeRejected $rejected) {
                $this->record(self::findingFor($rejected), storedEventId: $storedEventId);
                $this->classify(VerificationClassification::Inconsistent);

                continue;
            }

            // THE EVENT ID IS CHECKED HERE AND THE PAYMENT ID IS NOT, and the
            // asymmetry is the point. `paymentId` was already compared to
            // `stored_events.aggregate_uuid`, a uuid column PostgreSQL has already
            // refused anything malformed for, so its form is guaranteed
            // transitively. The durable event id has NO canonical column to be
            // compared against - it exists only inside the payload - so nothing has
            // vetted it before this line.
            //
            // AND IT IS ABOUT TO BECOME A QUERY PARAMETER against three uuid
            // columns. Sending a malformed one raises 22P02, which would abort the
            // WHOLE audit as a database error: one corrupt payload would hide every
            // other finding behind an incomplete run, and the corruption itself
            // would never be reported. It is a finding about this event instead.
            //
            // Str::isUuid accepts only the canonical hyphenated form, the same
            // stricter-than-PostgreSQL rule DatabasePaymentQuery applies, and the
            // only spelling this system ever writes.
            if (! Str::isUuid($decoded[$storedEventId]->eventId)) {
                $this->record(VerificationFinding::CanonicalPayloadMalformed, storedEventId: $storedEventId);
                $this->classify(VerificationClassification::Inconsistent);

                unset($decoded[$storedEventId], $payloads[$storedEventId]);

                continue;
            }

            $eventIds[] = $decoded[$storedEventId]->eventId;
            $paymentIds[] = $decoded[$storedEventId]->paymentId;
        }

        if ($decoded === []) {
            return null;
        }

        $envelopes = $this->envelopesFor($eventIds);
        $receipts = $this->receiptsFor($eventIds);
        [$detailsBySource, $detailsByPayment] = $this->readRowsFor($eventIds, $paymentIds);

        foreach ($decoded as $storedEventId => $event) {
            $envelope = $envelopes[$event->eventId] ?? null;

            // The envelope's own payload is subject to the same cap, and for the
            // same reason: an uninspected payload cannot be compared.
            if ($envelope !== null && $envelope->payload === null) {
                return self::REASON_PAYLOAD_TOO_LARGE;
            }

            $this->evaluateEvent(
                storedEventId: $storedEventId,
                event: $event,
                canonicalPayload: $payloads[$storedEventId],
                envelope: $envelope,
                receiptAt: $receipts[$event->eventId] ?? null,
                bySource: $detailsBySource[$event->eventId] ?? null,
                byPayment: $detailsByPayment[$event->paymentId] ?? null,
            );
        }

        return null;
    }

    /**
     * One canonical event against everything derived from it.
     *
     * FINDINGS FIRST, CLASSIFICATION LAST, and never the other way round: a
     * contradiction outranks every delivery state, so grace can never suppress a
     * mismatch on a recent event. The delivery states are only consulted when
     * nothing was contradicted and nothing was derived yet.
     *
     * @param  DetailsRow|null  $bySource
     * @param  DetailsRow|null  $byPayment
     */
    private function evaluateEvent(
        int $storedEventId,
        PaymentInitiatedEnvelope $event,
        string $canonicalPayload,
        ?VerificationEnvelope $envelope,
        ?string $receiptAt,
        ?object $bySource,
        ?object $byPayment,
    ): void {
        $contradicted = false;

        if ($envelope === null) {
            // NOT LAG, EVER. T6 commits the stored event and its initial envelope
            // in one transaction, so a visible event without one was deleted or
            // never written - there is no in-flight state this can be.
            $this->record(VerificationFinding::OutboxEnvelopeMissing, $storedEventId, $event->eventId);
            $contradicted = true;
        } else {
            $mismatched = $envelope->mismatchedFields($event, $canonicalPayload);

            if ($mismatched !== []) {
                $this->record(
                    VerificationFinding::OutboxFieldMismatch,
                    storedEventId: $storedEventId,
                    eventId: $event->eventId,
                    fields: $mismatched,
                );
                $contradicted = true;
            }
        }

        $hasReceipt = $receiptAt !== null;
        $marker = $envelope?->projectionCompletedAt;

        if ($hasReceipt && $bySource === null) {
            $this->record(VerificationFinding::ProjectorReceiptWithoutReadRow, $storedEventId, $event->eventId);
            $contradicted = true;
        }

        if (! $hasReceipt && $bySource !== null) {
            $this->record(VerificationFinding::ReadRowWithoutProjectorReceipt, $storedEventId, $event->eventId);
            $contradicted = true;
        }

        if ($bySource !== null) {
            $mismatched = $this->readRowMismatches($event, $bySource);

            if ($mismatched !== []) {
                $this->record(
                    VerificationFinding::ReadRowFieldMismatch,
                    storedEventId: $storedEventId,
                    eventId: $event->eventId,
                    fields: $mismatched,
                );
                $contradicted = true;
            }
        }

        // THE OTHER DIRECTION OF SOURCE IDENTITY, reachable from the canonical
        // side: this payment has a read row, and it was projected from some other
        // event. Looking only by source_event_id would report the row as merely
        // missing and lose the fact that a different event claims this payment.
        if ($byPayment !== null && $byPayment->source_event_id !== $event->eventId) {
            $this->record(VerificationFinding::ReadRowWrongSourceEvent, $storedEventId, $event->eventId);
            $contradicted = true;
        }

        // ONLY WHEN THERE IS AN ENVELOPE TO CARRY A MARKER. The marker is a column
        // on the envelope, so with no envelope its absence is implied by the
        // missing-envelope finding rather than being a second fact. Reporting both
        // would send an operator to repair a column on a row that is not there.
        if ($envelope !== null) {
            if ($marker !== null && ! $hasReceipt) {
                $this->record(
                    VerificationFinding::CompletionMarkerWithoutReceipt,
                    storedEventId: $storedEventId,
                    eventId: $event->eventId,
                );
                $contradicted = true;
            }

            if ($marker === null && $hasReceipt) {
                $this->record(
                    VerificationFinding::CompletionMarkerMissing,
                    storedEventId: $storedEventId,
                    eventId: $event->eventId,
                );
                $contradicted = true;
            }
        }

        if ($contradicted) {
            $this->classify(VerificationClassification::Inconsistent);

            return;
        }

        if ($hasReceipt && $bySource !== null && $marker !== null) {
            $this->classify(VerificationClassification::Consistent);

            return;
        }

        $this->classify($this->deliveryStateOf($storedEventId, $event, $envelope));
    }

    /**
     * An incomplete, non-contradicted delivery: stopped, late, or still in flight.
     *
     * AGE IS EVENT AGE, from canonical occurredAt to the fixed audit timestamp, and
     * it is deliberately not measured from `last_published_at` or `available_at`:
     * republication would otherwise rejuvenate an old event forever and an audit
     * would never call anything overdue.
     */
    private function deliveryStateOf(
        int $storedEventId,
        PaymentInitiatedEnvelope $event,
        ?VerificationEnvelope $envelope,
    ): VerificationClassification {
        if ($envelope?->exhaustedAt !== null) {
            return VerificationClassification::Exhausted;
        }

        $auditInstant = $this->auditInstant;

        if ($auditInstant !== null && $event->occurredAt->greaterThan($auditInstant)) {
            // AN OPERATIONAL WARNING, NOT AN INFERRED CORRUPTION FINDING. A future
            // event time says something is wrong with a clock, which is neither a
            // contradiction between two records nor an ordinary in-flight delivery.
            $this->record(VerificationFinding::EventTimeInFuture, $storedEventId, $event->eventId);

            return VerificationClassification::InFlight;
        }

        $ageSeconds = $auditInstant === null
            ? 0
            : $event->occurredAt->diffInSeconds($auditInstant, absolute: false);

        return $ageSeconds < $this->config->graceSeconds
            ? VerificationClassification::InFlight
            : VerificationClassification::DeliveryOverdue;
    }

    /**
     * The `payment_details` columns that disagree with canonical history.
     *
     * NAMES, NEVER VALUES. The caller puts this list straight into an example that
     * reaches stdout, so returning the amounts that differed would publish payment
     * data to diagnose a mismatch.
     *
     * @param  DetailsRow  $row
     * @return list<string>
     */
    private function readRowMismatches(PaymentInitiatedEnvelope $event, object $row): array
    {
        $mismatched = [];

        if ($row->payment_id !== $event->paymentId) {
            $mismatched[] = 'payment_id';
        }

        if ((int) $row->merchant_id !== $event->merchantId) {
            $mismatched[] = 'merchant_id';
        }

        if ((int) $row->amount_minor !== $event->amountMinor) {
            $mismatched[] = 'amount_minor';
        }

        if ($row->currency !== $event->currency) {
            $mismatched[] = 'currency';
        }

        if ($row->status !== PaymentProjectionConsumer::INITIATED_STATUS) {
            $mismatched[] = 'status';
        }

        if ((int) $row->aggregate_version !== $event->aggregateVersion) {
            $mismatched[] = 'aggregate_version';
        }

        // INSTANTS, NOT STRINGS, and to the microsecond. The column comes back in
        // PostgreSQL's own rendering at whatever offset the session displays, so a
        // textual comparison would report a mismatch for two identical instants -
        // and could miss a real sub-second drift.
        if (! CarbonImmutable::parse($row->initiated_at)->equalTo($event->occurredAt)) {
            $mismatched[] = 'initiated_at';
        }

        return $mismatched;
    }

    /**
     * @param  list<string>  $eventIds
     * @return array<string, VerificationEnvelope>
     */
    private function envelopesFor(array $eventIds): array
    {
        $rows = $this->connection->select(
            <<<'SQL'
            select event_id,
                   event_type,
                   schema_version,
                   aggregate_uuid,
                   aggregate_version,
                   occurred_at,
                   projection_completed_at,
                   exhausted_at,
                   case
                       when octet_length(payload::text) <= ?
                       then payload::text
                   end as payload
              from payment_outbox
             where event_id in (
            SQL.$this->placeholders($eventIds).')',
            [$this->config->maxPayloadBytes, ...$eventIds],
        );

        /** @var list<object{event_id: string, event_type: string, schema_version: int|string, aggregate_uuid: string, aggregate_version: int|string, occurred_at: string, projection_completed_at: string|null, exhausted_at: string|null, payload: string|null}> $rows */
        $envelopes = [];

        foreach ($rows as $row) {
            $envelopes[$row->event_id] = VerificationEnvelope::fromRow($row);
        }

        return $envelopes;
    }

    /**
     * The named projector's receipts, as event id to creation time.
     *
     * THE PROJECTOR NAME IS PART OF THE QUESTION. A receipt written by some other
     * projector says nothing about whether `payment_details` was built, and
     * treating one as completion evidence would retire an envelope whose work was
     * never done.
     *
     * @param  list<string>  $eventIds
     * @return array<string, string>
     */
    private function receiptsFor(array $eventIds): array
    {
        $rows = $this->connection->select(
            <<<'SQL'
            select event_id, created_at
              from payment_projector_receipts
             where projector_name = ?
               and event_id in (
            SQL.$this->placeholders($eventIds).')',
            [PaymentProjectionConsumer::PROJECTOR_NAME, ...$eventIds],
        );

        /** @var list<object{event_id: string, created_at: string}> $rows */
        $receipts = [];

        foreach ($rows as $row) {
            $receipts[$row->event_id] = $row->created_at;
        }

        return $receipts;
    }

    /**
     * Read rows for this batch, indexed by BOTH keys from one statement.
     *
     * ONE QUERY, TWO INDEXES. The source-event key answers "was this event
     * projected"; the payment key answers "was this payment projected from
     * something else". Two statements would ask the same table the same batch of
     * questions twice.
     *
     * @param  list<string>  $eventIds
     * @param  list<string>  $paymentIds
     * @return array{0: array<string, DetailsRow>, 1: array<string, DetailsRow>}
     */
    private function readRowsFor(array $eventIds, array $paymentIds): array
    {
        $rows = $this->connection->select(
            <<<'SQL'
            select payment_id, source_event_id, merchant_id, amount_minor, currency,
                   status, aggregate_version, initiated_at
              from payment_details
             where source_event_id in (
            SQL.$this->placeholders($eventIds).') or payment_id in ('.$this->placeholders($paymentIds).')',
            [...$eventIds, ...$paymentIds],
        );

        /** @var list<DetailsRow> $rows */
        $bySource = [];
        $byPayment = [];

        foreach ($rows as $row) {
            $bySource[$row->source_event_id] = $row;
            $byPayment[$row->payment_id] = $row;
        }

        return [$bySource, $byPayment];
    }

    /**
     * Canonical Payments events that share one durable event id.
     *
     * A GROUPED QUERY, NOT THE FORWARD WALK, and that is forced rather than
     * chosen: two rows carrying the same id can land in different batches, so a
     * walk could only catch them by holding every id it had seen in memory - the
     * one thing the bounds forbid. PostgreSQL groups them without transferring
     * history into PHP.
     *
     * THE EXACT COUNT AND A BOUNDED SAMPLE, from one statement. `count(*) over ()`
     * is evaluated over the whole grouped set before `limit` applies, so the total
     * is honest even when only a few ids are carried back.
     *
     * NULL IDS ARE EXCLUDED. A payload with no `eventId` is malformed, which the
     * canonical pass already reports against its own row; grouping them here would
     * report two unrelated corrupt rows as duplicates of each other.
     */
    private function findDuplicateCanonicalEventIds(int $deadline): ?string
    {
        if (($this->clock)() >= $deadline) {
            return self::REASON_WORK_BUDGET;
        }

        /** @var list<object{event_id: string, duplicated_ids: int|string}> $rows */
        $rows = $this->connection->select(
            <<<'SQL'
            select duplicates.event_id,
                   count(*) over () as duplicated_ids
              from (
                  select event_properties->>'eventId' as event_id
                    from stored_events
                   where event_class like ?
                     and event_properties->>'eventId' is not null
                   group by 1
                  having count(*) > 1
              ) duplicates
             order by duplicates.event_id
             limit ?
            SQL,
            [self::PAYMENTS_ALIAS_PREFIX.'%', $this->config->batchSize],
        );

        if ($rows === []) {
            return null;
        }

        $total = (int) $rows[0]->duplicated_ids;
        $carried = 0;

        foreach ($rows as $row) {
            // The id is reported only when it is a canonical UUID, for the reason
            // the forward walk checks the same thing: an unusable identifier is a
            // malformed payload, already reported there, and must not be echoed.
            $this->record(
                VerificationFinding::CanonicalDuplicateEventId,
                eventId: Str::isUuid($row->event_id) ? $row->event_id : null,
            );
            $carried++;
        }

        // The ids beyond the sample are counted without being carried, so the
        // total is right and the retained diagnostics stay capped.
        $this->recordWithoutExample(VerificationFinding::CanonicalDuplicateEventId, $total - $carried);

        return null;
    }

    /**
     * Every receipt this projection wrote, asked whether anything still accounts
     * for it.
     *
     * WHY IT RESOLVES THROUGH THE ENVELOPE. A receipt carries only a projector
     * name and an event id; it has no aggregate identity of its own. The durable
     * event id lives inside `stored_events.event_properties`, which is JSON with no
     * index, so asking canonical history about it directly would mean a scan of
     * history for every batch. The envelope holds the same event id as its PRIMARY
     * KEY and carries the aggregate identity that `stored_events` has a unique
     * index on, so the same question is answered by two indexed lookups.
     *
     * A MISSING ENVELOPE IS ITS OWN ANSWER, not a failure to resolve. The consumer
     * writes a receipt only while holding that envelope's row lock, so the pair
     * cannot come apart through an interrupted delivery - something removed the
     * envelope afterwards, and there is then no identity left to check canonical
     * history with. Reporting it as an orphan would claim history had been checked
     * when it had not.
     *
     * IT RESOLVES ON THE AGGREGATE UUID ALONE, NOT THE VERSION PAIR, and the
     * difference is what keeps the label honest. An envelope whose
     * `aggregate_version` has been corrupted still belongs to a payment canonical
     * history knows about; the corrupt column is the ENVELOPE pass's finding and
     * the forward walk's field mismatch. Resolving this pass on the pair would make
     * that one corruption additionally accuse a receipt that is perfectly intact,
     * and send an operator looking for a deleted event that never existed.
     */
    private function scanProjectorReceipts(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_PROJECTOR_RECEIPTS);

        $cursor = self::NIL_UUID;

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{event_id: string, has_envelope: bool, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<'SQL'
                select receipts.event_id,
                       envelope.event_id is not null as has_envelope,
                       exists (
                           select 1
                             from stored_events canonical
                            where canonical.aggregate_uuid = envelope.aggregate_uuid
                              and canonical.event_class like ?
                       ) as has_canonical
                  from payment_projector_receipts receipts
                  left join payment_outbox envelope on envelope.event_id = receipts.event_id
                 where receipts.projector_name = ?
                   and receipts.event_id > ?
                 order by receipts.event_id
                 limit ?
                SQL,
                [
                    self::PAYMENTS_ALIAS_PREFIX.'%',
                    PaymentProjectionConsumer::PROJECTOR_NAME,
                    $cursor,
                    $this->config->batchSize,
                ],
            );

            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $this->scanned[VerificationReport::PASS_PROJECTOR_RECEIPTS]++;

                if (! $row->has_envelope) {
                    $this->record(
                        VerificationFinding::ProjectorReceiptWithoutEnvelope,
                        eventId: $row->event_id,
                    );

                    continue;
                }

                if (! $row->has_canonical) {
                    $this->record(VerificationFinding::ProjectorReceiptOrphaned, eventId: $row->event_id);
                }

                // A receipt whose envelope resolves to canonical history is left to
                // the forward walk, which owns whether the two AGREE. Reporting it
                // from both directions would double the counts for one corruption.
            }

            $cursor = $rows[count($rows) - 1]->event_id;

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    /**
     * Every projected row, asked whether canonical history accounts for it.
     *
     * KEYED ON payment_id, which is `stored_events.aggregate_uuid` and carries its
     * own index. The row's `source_event_id` and `aggregate_version` are the other
     * links, and neither is the question here: a row whose payment exists
     * canonically but was projected from some other event, or which disagrees on a
     * business column, is found by the forward walk, which can compare both sides
     * because it holds the canonical one. This pass answers only what that walk
     * structurally cannot reach - a row whose payment has no canonical event at
     * all - so one corruption is described by one finding.
     */
    private function scanReadRows(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_READ_ROWS);

        $cursor = self::NIL_UUID;

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{payment_id: string, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<'SQL'
                select details.payment_id,
                       exists (
                           select 1
                             from stored_events canonical
                            where canonical.aggregate_uuid = details.payment_id
                              and canonical.event_class like ?
                       ) as has_canonical
                  from payment_details details
                 where details.payment_id > ?
                 order by details.payment_id
                 limit ?
                SQL,
                [self::PAYMENTS_ALIAS_PREFIX.'%', $cursor, $this->config->batchSize],
            );

            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $this->scanned[VerificationReport::PASS_READ_ROWS]++;

                if (! $row->has_canonical) {
                    $this->record(VerificationFinding::ReadRowOrphaned, paymentId: $row->payment_id);
                }
            }

            $cursor = $rows[count($rows) - 1]->payment_id;

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    /**
     * Every delivery envelope, asked whether canonical history accounts for it.
     *
     * KEYED ON (aggregate_uuid, aggregate_version), the PAIR - unlike the two
     * passes above, and deliberately. `payment_outbox` enforces its own unique
     * constraint on exactly that pair precisely because it mirrors the key
     * `stored_events` enforces, so the pair IS this table's canonical identity and
     * a version that resolves to nothing is this pass's own finding rather than
     * someone else's.
     *
     * A CORRUPT VERSION IS THEREFORE REPORTED TWICE, and that is intended rather
     * than tolerated: the forward walk says the envelope for THIS event has a wrong
     * column, and this pass says the envelope as it now stands matches no canonical
     * event. Both are true, they are different facts, and an operator needs the
     * second one to know the row is unreachable from history as well as wrong.
     *
     * An envelope whose aggregate identity DOES resolve but whose `event_id` is not
     * that event's durable id is reported by the forward walk as a missing envelope
     * for the canonical event - once, from the side that can see both ids.
     */
    private function scanOutboxEnvelopes(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_OUTBOX_ENVELOPES);

        $cursor = self::NIL_UUID;

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{event_id: string, aggregate_uuid: string, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<'SQL'
                select envelope.event_id,
                       envelope.aggregate_uuid,
                       exists (
                           select 1
                             from stored_events canonical
                            where canonical.aggregate_uuid = envelope.aggregate_uuid
                              and canonical.aggregate_version = envelope.aggregate_version
                              and canonical.event_class like ?
                       ) as has_canonical
                  from payment_outbox envelope
                 where envelope.event_id > ?
                 order by envelope.event_id
                 limit ?
                SQL,
                [self::PAYMENTS_ALIAS_PREFIX.'%', $cursor, $this->config->batchSize],
            );

            if ($rows === []) {
                return null;
            }

            foreach ($rows as $row) {
                $this->scanned[VerificationReport::PASS_OUTBOX_ENVELOPES]++;

                if (! $row->has_canonical) {
                    $this->record(
                        VerificationFinding::OutboxEnvelopeOrphaned,
                        eventId: $row->event_id,
                        paymentId: $row->aggregate_uuid,
                    );
                }
            }

            $cursor = $rows[count($rows) - 1]->event_id;

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    /**
     * Positional placeholders for a bounded list.
     *
     * The lists here are one batch of canonical events, so their length is the
     * configured batch size at most - never "however many rows matched".
     *
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** Which finding a shared envelope refusal is, on the canonical path. */
    private static function findingFor(ProjectionEnvelopeRejected $rejected): VerificationFinding
    {
        return match ($rejected->category) {
            ProjectionEnvelopeRejected::UNSUPPORTED_ALIAS => VerificationFinding::CanonicalAliasUnsupported,
            ProjectionEnvelopeRejected::UNSUPPORTED_SCHEMA_VERSION => VerificationFinding::CanonicalSchemaVersionUnsupported,
            ProjectionEnvelopeRejected::UNSUPPORTED_AGGREGATE_VERSION => VerificationFinding::CanonicalAggregateVersionUnsupported,
            ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY => VerificationFinding::CanonicalIdentityContradictory,
            default => VerificationFinding::CanonicalPayloadMalformed,
        };
    }

    /**
     * Count a finding always; retain its example only while there is room.
     *
     * THE COUNT IS NOT CAPPED AND THE EXAMPLES ARE. An operator needs to know that
     * four thousand events failed even when only fifty can be shown, and
     * `examples_truncated` is what stops the shown fifty from reading as the whole
     * of it.
     *
     * @param  list<string>  $fields
     */
    private function record(
        VerificationFinding $finding,
        ?int $storedEventId = null,
        ?string $eventId = null,
        ?string $paymentId = null,
        array $fields = [],
    ): void {
        $this->findingCounts[$finding->value] = ($this->findingCounts[$finding->value] ?? 0) + 1;

        if (count($this->examples) >= $this->config->exampleLimit) {
            $this->examplesTruncated = true;

            return;
        }

        $this->examples[] = new VerificationExample($finding, $storedEventId, $eventId, $paymentId, $fields);
    }

    /**
     * Count findings the audit knows about but is deliberately not carrying.
     *
     * FOR A COUNT PostgreSQL PRODUCED, not for one PHP could have. It exists so a
     * bounded sample of an unbounded set still reports the true total, and it
     * marks the diagnostics truncated for exactly the reason the cap does.
     */
    private function recordWithoutExample(VerificationFinding $finding, int $additional): void
    {
        if ($additional <= 0) {
            return;
        }

        $this->findingCounts[$finding->value] = ($this->findingCounts[$finding->value] ?? 0) + $additional;
        $this->examplesTruncated = true;
    }

    /** A pass declares itself started, so a pass that never ran leaves no key. */
    private function beginPass(string $pass): void
    {
        $this->scanned[$pass] ??= 0;
    }

    private function classify(VerificationClassification $classification): void
    {
        $this->classificationCounts[$classification->value]++;
    }

    /**
     * Finding counts in the enum's declaration order.
     *
     * DETERMINISTIC OUTPUT, so two runs over the same state produce byte-identical
     * JSON and a diff between them means the database changed rather than that a
     * hash map iterated differently.
     *
     * @return array<string, int>
     */
    private function sortedFindingCounts(): array
    {
        $sorted = [];

        foreach (VerificationFinding::cases() as $case) {
            if (isset($this->findingCounts[$case->value])) {
                $sorted[$case->value] = $this->findingCounts[$case->value];
            }
        }

        return $sorted;
    }

    private function partial(string $reason): VerificationReport
    {
        return VerificationReport::incomplete(
            reason: $reason,
            highWatermark: $this->highWatermark,
            auditAt: $this->auditAt,
            scanned: $this->scanned,
            classificationCounts: $this->classificationCounts,
            findingCounts: $this->sortedFindingCounts(),
            examples: $this->examples,
            examplesTruncated: $this->examplesTruncated,
        );
    }

    /**
     * A stable category for the failure, never the driver's wording.
     *
     * transaction_timeout TERMINATES THE SESSION rather than cancelling a
     * statement, so it arrives as a lost connection and is reported as a database
     * error. It is deliberately NOT reconnected and resumed: the snapshot it was
     * bounding no longer exists, and continuing on a new backend would silently
     * mix two different views of history into one report.
     */
    private function safeReason(Throwable $failure): string
    {
        return match (RetryingAtomicTransaction::sqlStateOf($failure)) {
            self::STATEMENT_TIMEOUT_SQLSTATE => self::REASON_STATEMENT_TIMEOUT,
            self::READ_ONLY_SQLSTATE => self::REASON_READ_ONLY_VIOLATION,
            default => self::REASON_DATABASE_ERROR,
        };
    }

    private function rollBackQuietly(): void
    {
        if ($this->connection->transactionLevel() <= 0) {
            return;
        }

        try {
            $this->connection->rollBack();
        } catch (Throwable) {
            // Nothing durable is at stake - the transaction wrote nothing by
            // construction - and a second exception here would only obscure the
            // first.
        }
    }

    /** A fresh audit every invocation; nothing carries over from the last one. */
    private function reset(): void
    {
        $this->classificationCounts = VerificationClassification::zeroed();
        $this->findingCounts = [];
        $this->examples = [];
        $this->examplesTruncated = false;
        $this->scanned = [];
        $this->highWatermark = 0;
        $this->auditAt = null;
        $this->auditInstant = null;
    }
}
