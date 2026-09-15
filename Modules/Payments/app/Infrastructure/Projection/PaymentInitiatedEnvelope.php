<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Throwable;

// Structural and identity checks only: never rebuild PaymentInitiated, so history is not
// re-validated against today's business rules.
final readonly class PaymentInitiatedEnvelope
{
    public const int SCHEMA_VERSION = 1;

    public const int FIRST_AGGREGATE_VERSION = 1;

    private const array PAYLOAD_KEYS = [
        'eventId', 'paymentId', 'merchantId', 'amountMinor', 'currency', 'occurredAt',
    ];

    // Structural only; the accepted currency set is Domain policy.
    private const string CURRENCY_PATTERN = '/\A[A-Z]{3}\z/';

    private function __construct(
        public string $eventId,
        public string $paymentId,
        public int $merchantId,
        public int $amountMinor,
        public string $currency,
        public CarbonImmutable $occurredAt,
        public int $aggregateVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromOutboxRow(array $row): self
    {
        $eventId = self::string($row, 'event_id');

        $alias = self::string($row, 'event_type');

        if ($alias !== PaymentInitiated::ALIAS) {
            throw ProjectionEnvelopeRejected::unsupportedAlias($eventId, $alias);
        }

        $schemaVersion = self::integer($row, 'schema_version');

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedSchemaVersion($eventId, $schemaVersion);
        }

        $aggregateVersion = self::integer($row, 'aggregate_version');

        if ($aggregateVersion !== self::FIRST_AGGREGATE_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedAggregateVersion($eventId, $aggregateVersion);
        }

        $payload = self::payload($eventId, $row);

        if ($payload['eventId'] !== $eventId) {
            throw ProjectionEnvelopeRejected::contradictoryIdentity($eventId, 'eventId');
        }

        if ($payload['paymentId'] !== self::string($row, 'aggregate_uuid')) {
            throw ProjectionEnvelopeRejected::contradictoryIdentity($eventId, 'paymentId');
        }

        $occurredAt = self::occurredAt($eventId, $payload['occurredAt'], self::string($row, 'occurred_at'));

        return new self(
            eventId: $eventId,
            paymentId: $payload['paymentId'],
            merchantId: $payload['merchantId'],
            amountMinor: $payload['amountMinor'],
            currency: $payload['currency'],
            occurredAt: $occurredAt,
            aggregateVersion: $aggregateVersion,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromStoredEventRow(array $row): self
    {
        // Refusals name the aggregate uuid: the durable event id is untrusted until the payload validates.
        $paymentId = self::string($row, 'aggregate_uuid');

        $alias = self::string($row, 'event_class');

        if ($alias !== PaymentInitiated::ALIAS) {
            throw ProjectionEnvelopeRejected::unsupportedAlias($paymentId, $alias);
        }

        $schemaVersion = self::integer($row, 'event_version');

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedSchemaVersion($paymentId, $schemaVersion);
        }

        $aggregateVersion = self::integer($row, 'aggregate_version');

        if ($aggregateVersion !== self::FIRST_AGGREGATE_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedAggregateVersion($paymentId, $aggregateVersion);
        }

        $payload = self::payload($paymentId, ['payload' => $row['event_properties'] ?? null]);

        if ($payload['paymentId'] !== $paymentId) {
            throw ProjectionEnvelopeRejected::contradictoryIdentity($paymentId, 'paymentId');
        }

        $occurredAt = self::canonicalOccurredAt($paymentId, $payload['occurredAt']);

        return new self(
            eventId: $payload['eventId'],
            paymentId: $payload['paymentId'],
            merchantId: $payload['merchantId'],
            amountMinor: $payload['amountMinor'],
            currency: $payload['currency'],
            occurredAt: $occurredAt,
            aggregateVersion: $aggregateVersion,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{eventId: string, paymentId: string, merchantId: int, amountMinor: int, currency: string, occurredAt: string}
     */
    private static function payload(string $eventId, array $row): array
    {
        $raw = $row['payload'] ?? null;

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'payload is not a JSON object');
        }

        $keys = array_keys($decoded);
        sort($keys);
        $expected = self::PAYLOAD_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            // Name only the expected keys: incoming keys are untrusted and reach retained failed-job records.
            throw ProjectionEnvelopeRejected::malformedPayload(
                $eventId,
                'payload keys are not exactly '.implode(',', $expected),
            );
        }

        foreach (['eventId', 'paymentId', 'currency', 'occurredAt'] as $key) {
            if (! is_string($decoded[$key])) {
                throw ProjectionEnvelopeRejected::malformedPayload($eventId, "{$key} is not a string");
            }
        }

        // is_int, not is_numeric: a numeric string or float means the payload went through a lossy round trip.
        foreach (['merchantId', 'amountMinor'] as $key) {
            if (! is_int($decoded[$key])) {
                throw ProjectionEnvelopeRejected::malformedPayload($eventId, "{$key} is not an integer");
            }
        }

        if ($decoded['merchantId'] < 1) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'merchantId is not positive');
        }

        if ($decoded['amountMinor'] < 1) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'amountMinor is not positive');
        }

        if (preg_match(self::CURRENCY_PATTERN, $decoded['currency']) !== 1) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'currency is not three uppercase letters');
        }

        return [
            'eventId' => $decoded['eventId'],
            'paymentId' => $decoded['paymentId'],
            'merchantId' => $decoded['merchantId'],
            'amountMinor' => $decoded['amountMinor'],
            'currency' => $decoded['currency'],
            'occurredAt' => $decoded['occurredAt'],
        ];
    }

    private static function occurredAt(string $eventId, string $payloadValue, string $columnValue): CarbonImmutable
    {
        // Carbon reports a bad format by throwing or returning null, never false.
        try {
            $parsed = CarbonImmutable::createFromFormat(PaymentInitiated::OCCURRED_AT_FORMAT, $payloadValue);
        } catch (Throwable) {
            $parsed = null;
        }

        // The re-format comparison rejects dates createFromFormat rolls forward, such as 2026-02-30.
        if ($parsed === null || $parsed->format(PaymentInitiated::OCCURRED_AT_FORMAT) !== $payloadValue) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurredAt is not in the durable format');
        }

        try {
            $column = CarbonImmutable::parse($columnValue);
        } catch (Throwable) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurred_at column is not a timestamp');
        }

        if (! $parsed->equalTo($column)) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurredAt disagrees with occurred_at');
        }

        return $parsed;
    }

    private static function canonicalOccurredAt(string $identifier, string $payloadValue): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat(PaymentInitiated::OCCURRED_AT_FORMAT, $payloadValue);
        } catch (Throwable) {
            $parsed = null;
        }

        if ($parsed === null || $parsed->format(PaymentInitiated::OCCURRED_AT_FORMAT) !== $payloadValue) {
            throw ProjectionEnvelopeRejected::malformedPayload($identifier, 'occurredAt is not in the durable format');
        }

        return $parsed;
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value)) {
            throw ProjectionEnvelopeRejected::malformedEnvelope($column);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        // is_numeric: PDO may return PostgreSQL integer columns as strings.
        if (! is_numeric($value)) {
            throw ProjectionEnvelopeRejected::malformedEnvelope($column);
        }

        return (int) $value;
    }
}
