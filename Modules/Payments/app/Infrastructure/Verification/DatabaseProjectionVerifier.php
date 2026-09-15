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
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionGateState;
use Override;
use stdClass;
use Throwable;

// One REPEATABLE READ, READ ONLY snapshot. The watermark is the maximum visible id, not a commit-order fence.
/**
 * @phpstan-type CanonicalRow object{id: int|string, aggregate_uuid: string, aggregate_version: int|string, event_version: int|string, event_class: string, event_properties: string|null}
 * @phpstan-type DetailsRow object{payment_id: string, source_event_id: string, merchant_id: int|string, amount_minor: int|string, currency: string, status: string, aggregate_version: int|string, initiated_at: string}
 */
final class DatabaseProjectionVerifier implements ProjectionVerifier
{
    private const string PAYMENTS_ALIAS_PREFIX = 'payments.';

    // The first page compares with >=: corrupted derived state need not hold an application-generated UUID.
    private const string NIL_UUID = '00000000-0000-0000-0000-000000000000';

    private const string READ_ONLY_SQLSTATE = '25006';

    private const string STATEMENT_TIMEOUT_SQLSTATE = '57014';

    public const string REASON_WORK_BUDGET = 'work_budget_exhausted';

    public const string REASON_PAYLOAD_TOO_LARGE = 'payload_exceeds_inspection_limit';

    public const string REASON_STATEMENT_TIMEOUT = 'statement_timeout';

    public const string REASON_READ_ONLY_VIOLATION = 'read_only_violation';

    public const string REASON_DATABASE_ERROR = 'database_error';

    public const string REASON_REBUILD_IN_PROGRESS = 'rebuild_in_progress';

    /** @var array<string, int> */
    private array $classificationCounts;

    /** @var array<string, int> */
    private array $findingCounts = [];

    /** @var list<VerificationExample> */
    private array $examples = [];

    private bool $examplesTruncated = false;

    // A key appears only when its pass starts, so a skipped pass cannot be reported as complete.
    /** @var array<string, int> */
    private array $scanned = [];

    private ?int $highWatermark = null;

    private ?string $auditAt = null;

    private ?CarbonImmutable $auditInstant = null;

    private ?string $projectionControlState = null;

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

        // Never borrow a caller's transaction: a savepoint has no snapshot or READ ONLY guarantee of its own.
        if ($this->connection->transactionLevel() !== 0) {
            return $this->partial(self::REASON_DATABASE_ERROR);
        }

        $deadline = ($this->clock)() + $this->config->workBudgetNanoseconds();
        $transactionStarted = false;

        try {
            $this->connection->beginTransaction();
            $transactionStarted = true;

            $this->connection->statement('set transaction isolation level repeatable read, read only');
            $this->connection->statement($this->config->statementTimeoutStatement());
            $this->connection->statement($this->config->transactionTimeoutStatement());

            $gate = new ProjectionGate($this->connection)->inspectPaymentDetails();

            // Reported even when the lock refused entry; a row still saying ready never overrides that refusal.
            /** @var object{state: string}|null $control */
            $control = $this->connection->selectOne(
                'select state from payment_projection_controls where projection = ?',
                [ProjectionGate::PAYMENT_DETAILS],
            );
            $this->projectionControlState = $control?->state;

            if ($gate !== ProjectionGateState::Open) {
                $this->rollBackQuietly();

                return $this->partial(self::REASON_REBUILD_IN_PROGRESS);
            }

            $this->captureSnapshot();

            foreach ([
                $this->scanCanonicalEvents(...),
                $this->findDuplicateCanonicalEventIds(...),
                $this->scanProjectorReceipts(...),
                $this->scanReadRows(...),
                $this->scanOutboxEnvelopes(...),
            ] as $pass) {
                $reason = $pass($deadline);

                if ($reason !== null) {
                    $this->rollBackQuietly();

                    return $this->partial($reason);
                }
            }

            $this->connection->commit();

            return VerificationReport::completed(
                highWatermark: (int) $this->highWatermark,
                auditAt: (string) $this->auditAt,
                scanned: $this->scanned,
                classificationCounts: $this->classificationCounts,
                findingCounts: $this->sortedFindingCounts(),
                examples: $this->examples,
                examplesTruncated: $this->examplesTruncated,
                projectionControlState: $this->projectionControlState,
            );
        } catch (Throwable $failure) {
            if ($transactionStarted) {
                $this->rollBackQuietly();
            }

            return $this->partial($this->safeReason($failure));
        }
    }

    // now() is fixed for the transaction, so every event is aged against one instant.
    private function captureSnapshot(): void
    {
        $row = $this->connection->selectOne(
            'select coalesce(max(id), 0) as high_watermark, now() as audit_at from stored_events'
        );

        if (! $row instanceof stdClass || ! is_numeric($row->high_watermark) || ! is_string($row->audit_at)) {
            throw new VerificationSnapshotUnavailable(
                'The stored_events watermark query returned an unexpected shape; refusing to audit.'
            );
        }

        $this->highWatermark = (int) $row->high_watermark;
        $this->auditInstant = CarbonImmutable::parse($row->audit_at)->utc();
        $this->auditAt = $this->auditInstant->format(PaymentInitiated::OCCURRED_AT_FORMAT);
    }

    // The budget is checked before each batch, never inside one, so no event is half-evaluated.
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

    // A prefix match, not the decodable alias, so undecodable Payments events are reported rather than invisible.
    // Oversized payloads are withheld in SQL, before they are transferred.
    /**
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

            // A withheld payload stops the audit: uninspected history is neither consistent nor corrupt.
            if ($row->event_properties === null) {
                return self::REASON_PAYLOAD_TOO_LARGE;
            }

            try {
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

            // No uuid column vets the event id, and a malformed one would raise 22P02 and abort the whole audit.
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

    // Findings before classification: a contradiction outranks every delivery state, including grace.
    /**
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

        if ($byPayment !== null && $byPayment->source_event_id !== $event->eventId) {
            $this->record(VerificationFinding::ReadRowWrongSourceEvent, $storedEventId, $event->eventId);
            $contradicted = true;
        }

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

        $deliveryState = $this->deliveryStateOf($storedEventId, $event, $envelope);

        if ($deliveryState !== null) {
            $this->classify($deliveryState);
        }
    }

    // Age is event age: measuring from republication would keep an old event in flight forever.
    private function deliveryStateOf(
        int $storedEventId,
        PaymentInitiatedEnvelope $event,
        ?VerificationEnvelope $envelope,
    ): ?VerificationClassification {
        if ($envelope?->exhaustedAt !== null) {
            return VerificationClassification::Exhausted;
        }

        $auditInstant = $this->auditInstant;

        if ($auditInstant !== null && $event->occurredAt->greaterThan($auditInstant)) {
            $this->record(VerificationFinding::EventTimeInFuture, $storedEventId, $event->eventId);

            return null;
        }

        $ageSeconds = $auditInstant === null
            ? 0
            : $event->occurredAt->diffInSeconds($auditInstant, absolute: false);

        return $ageSeconds < $this->config->graceSeconds
            ? VerificationClassification::InFlight
            : VerificationClassification::DeliveryOverdue;
    }

    /**
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

    // A grouped query, not the forward walk: duplicates can span batches, and remembering every id is unbounded.
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
            $this->record(
                VerificationFinding::CanonicalDuplicateEventId,
                eventId: Str::isUuid($row->event_id) ? $row->event_id : null,
            );
            $carried++;
        }

        $this->recordWithoutExample(VerificationFinding::CanonicalDuplicateEventId, $total - $carried);

        return null;
    }

    // Resolves through the envelope's indexed keys instead of scanning event_properties; LATERAL LIMIT 1 keeps
    // the lookup parameterised. Aggregate uuid alone, so a corrupt envelope version cannot accuse an intact receipt.
    private function scanProjectorReceipts(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_PROJECTOR_RECEIPTS);

        $cursor = self::NIL_UUID;
        $comparison = '>=';

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{event_id: string, has_envelope: bool, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<SQL
                select receipts.event_id,
                       envelope.event_id is not null as has_envelope,
                       canonical.id is not null as has_canonical
                  from payment_projector_receipts receipts
                  left join payment_outbox envelope on envelope.event_id = receipts.event_id
                  left join lateral (
                           select id
                             from stored_events canonical
                            where canonical.aggregate_uuid = envelope.aggregate_uuid
                              and canonical.event_class like ?
                            limit 1
                       ) canonical on true
                 where receipts.projector_name = ?
                   and receipts.event_id {$comparison} ?
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
            }

            $cursor = $rows[count($rows) - 1]->event_id;
            $comparison = '>';

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    private function scanReadRows(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_READ_ROWS);

        $cursor = self::NIL_UUID;
        $comparison = '>=';

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{payment_id: string, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<SQL
                select details.payment_id,
                       canonical.id is not null as has_canonical
                  from payment_details details
                  left join lateral (
                           select id
                             from stored_events canonical
                            where canonical.aggregate_uuid = details.payment_id
                              and canonical.event_class like ?
                            limit 1
                       ) canonical on true
                 where details.payment_id {$comparison} ?
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
            $comparison = '>';

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    private function scanOutboxEnvelopes(int $deadline): ?string
    {
        $this->beginPass(VerificationReport::PASS_OUTBOX_ENVELOPES);

        $cursor = self::NIL_UUID;
        $comparison = '>=';

        while (true) {
            if (($this->clock)() >= $deadline) {
                return self::REASON_WORK_BUDGET;
            }

            /** @var list<object{event_id: string, aggregate_uuid: string, has_canonical: bool}> $rows */
            $rows = $this->connection->select(
                <<<SQL
                select envelope.event_id,
                       envelope.aggregate_uuid,
                       canonical.id is not null as has_canonical
                  from payment_outbox envelope
                  left join lateral (
                           select id
                             from stored_events canonical
                            where canonical.aggregate_uuid = envelope.aggregate_uuid
                              and canonical.aggregate_version = envelope.aggregate_version
                              and canonical.event_class like ?
                            limit 1
                       ) canonical on true
                 where envelope.event_id {$comparison} ?
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
            $comparison = '>';

            if (count($rows) < $this->config->batchSize) {
                return null;
            }
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

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

    private function recordWithoutExample(VerificationFinding $finding, int $additional): void
    {
        if ($additional <= 0) {
            return;
        }

        $this->findingCounts[$finding->value] = ($this->findingCounts[$finding->value] ?? 0) + $additional;
        $this->examplesTruncated = true;
    }

    private function beginPass(string $pass): void
    {
        $this->scanned[$pass] ??= 0;
    }

    private function classify(VerificationClassification $classification): void
    {
        $this->classificationCounts[$classification->value]++;
    }

    /**
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
            projectionControlState: $this->projectionControlState,
        );
    }

    // transaction_timeout ends the session: never reconnect and mix two snapshots into one report.
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
            // Read-only: nothing durable is at stake, and a second exception would obscure the first.
        }
    }

    private function reset(): void
    {
        $this->classificationCounts = VerificationClassification::zeroed();
        $this->findingCounts = [];
        $this->examples = [];
        $this->examplesTruncated = false;
        $this->scanned = [];
        $this->highWatermark = null;
        $this->auditAt = null;
        $this->auditInstant = null;
        $this->projectionControlState = null;
    }
}
