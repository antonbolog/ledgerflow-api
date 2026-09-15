<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Throwable;

// Raw columns, not PaymentInitiatedEnvelope: that decoder throws on exactly the corruption the audit must report.
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
            return false;
        }
    }

    // Semantic equality: key order and whitespace are ignored, but types are compared strictly.
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
