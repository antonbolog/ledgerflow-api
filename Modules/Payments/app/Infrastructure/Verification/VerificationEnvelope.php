<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Throwable;

/**
 * One `payment_outbox` row as the audit reads it: raw columns, compared later.
 *
 * NOT DECODED THROUGH PaymentInitiatedEnvelope, and that is the point. That
 * decoder REFUSES a row whose alias, version or identity is wrong - which is
 * exactly the corruption this audit exists to REPORT. Running derived state
 * through a validator that throws on the interesting cases would turn a
 * diagnosable mismatch into an exception, and the audit would learn less than a
 * comparison does.
 *
 * THE COMPARISON IS AGAINST CANONICAL HISTORY, NEVER AGAINST TODAY'S POLICY. The
 * expected values come from the stored event that was already decoded; nothing
 * here re-validates a historical payment against current business rules.
 *
 * A WITHHELD PAYLOAD IS NULL, NOT AN EMPTY STRING. It means the value was over
 * the inspection cap and was never transferred, which the caller turns into an
 * incomplete audit rather than into a payload mismatch.
 */
final readonly class VerificationEnvelope
{
    private function __construct(
        public string $eventType,
        public int $schemaVersion,
        public string $aggregateUuid,
        public int $aggregateVersion,
        public string $occurredAt,
        public ?string $payload,
        public ?string $projectionCompletedAt,
        public ?string $exhaustedAt,
    ) {}

    /**
     * THE SHAPE IS DECLARED, NOT CAST FROM mixed. pgsql may return an integer
     * column as either int or string depending on the driver's emulation setting,
     * which is a transport detail rather than a schema disagreement.
     *
     * @param  object{event_type: string, schema_version: int|string, aggregate_uuid: string, aggregate_version: int|string, occurred_at: string, projection_completed_at: string|null, exhausted_at: string|null, payload: string|null}  $row
     */
    public static function fromRow(object $row): self
    {
        return new self(
            eventType: $row->event_type,
            schemaVersion: (int) $row->schema_version,
            aggregateUuid: $row->aggregate_uuid,
            aggregateVersion: (int) $row->aggregate_version,
            occurredAt: $row->occurred_at,
            payload: $row->payload,
            projectionCompletedAt: $row->projection_completed_at,
            exhaustedAt: $row->exhausted_at,
        );
    }

    /**
     * The envelope columns that disagree with canonical history.
     *
     * NAMES ONLY, never the two values that differed: this list is printed.
     *
     * @return list<string>
     */
    public function mismatchedFields(PaymentInitiatedEnvelope $canonical, string $canonicalPayload): array
    {
        $mismatched = [];

        if ($this->eventType !== PaymentInitiated::ALIAS) {
            $mismatched[] = 'event_type';
        }

        if ($this->schemaVersion !== PaymentInitiatedEnvelope::SCHEMA_VERSION) {
            $mismatched[] = 'schema_version';
        }

        if ($this->aggregateUuid !== $canonical->paymentId) {
            $mismatched[] = 'aggregate_uuid';
        }

        if ($this->aggregateVersion !== $canonical->aggregateVersion) {
            $mismatched[] = 'aggregate_version';
        }

        // INSTANTS, TO THE MICROSECOND. Both sides render at whatever offset the
        // session displays, so comparing the strings would report a mismatch for
        // one instant spelled two ways.
        if (! $this->sameInstant($this->occurredAt, $canonical)) {
            $mismatched[] = 'occurred_at';
        }

        if (! self::sameJson($this->payload, $canonicalPayload)) {
            $mismatched[] = 'payload';
        }

        return $mismatched;
    }

    private function sameInstant(string $stored, PaymentInitiatedEnvelope $canonical): bool
    {
        try {
            return CarbonImmutable::parse($stored)->equalTo($canonical->occurredAt);
        } catch (Throwable) {
            // Unreachable from a timestamptz column, and answered rather than
            // suppressed: an unparseable value IS a disagreement.
            return false;
        }
    }

    /**
     * Semantic JSON equality, not textual.
     *
     * KEY ORDER AND WHITESPACE ARE NOT THE CONTRACT. Both columns are jsonb and
     * normally render identically, but a payload that had been rewritten by hand
     * or migrated through a different serializer can differ in spelling while
     * describing exactly the same object - and reporting that as a payload
     * mismatch would send an operator to repair a row that is already correct.
     *
     * TYPES STILL COUNT. The recursive sort is followed by a STRICT comparison, so
     * 1250 and "1250" remain different: a payload that had been through a lossy
     * round trip is a real corruption, not a rendering difference.
     */
    private static function sameJson(?string $left, string $right): bool
    {
        if ($left === null) {
            return false;
        }

        $decodedLeft = json_decode($left, true);
        $decodedRight = json_decode($right, true);

        if (! is_array($decodedLeft) || ! is_array($decodedRight)) {
            return false;
        }

        self::sortDeep($decodedLeft);
        self::sortDeep($decodedRight);

        return $decodedLeft === $decodedRight;
    }

    /** @param array<array-key, mixed> $value */
    private static function sortDeep(array &$value): void
    {
        foreach ($value as &$nested) {
            if (is_array($nested)) {
                self::sortDeep($nested);
            }
        }

        unset($nested);

        ksort($value);
    }
}
