<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Carbon\CarbonImmutable;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Throwable;

/**
 * One `payments.payment_initiated` schema version 1 envelope, validated once.
 *
 * WHY A TYPE AT ALL, RATHER THAN READING THE ROW INLINE. The query builder hands
 * back stdClass and jsonb decodes to `mixed`, so without this every business value
 * would reach the projection as an unchecked `mixed` and static analysis could say
 * nothing about any of it. Validating in one place, at the boundary, is what lets
 * the consumer below be ordinary typed code.
 *
 * IT IS NOT A GENERIC DTO AND NOT AN EVENT REGISTRY. It decodes exactly one alias
 * at exactly one schema version. A second event type gets its own type and its own
 * decision about what its invariants are; it does not get a framework here.
 *
 * IT DOES NOT RECONSTRUCT PaymentInitiated, AND THAT IS THE POINT. Handing the
 * stored payload back to the Domain constructor would bind every historical row to
 * whatever that constructor requires TODAY: the current one refuses any currency
 * but USD and any amount above 100,000,000, so the day either rule is widened or
 * narrowed, replaying old events would start failing on data that was valid when
 * it was written. History is not re-validated against future business rules.
 *
 * So the checks below are STRUCTURAL and IDENTITY checks — the payload has exactly
 * these keys, of exactly these types, and it agrees with the columns the outbox row
 * already carries — plus the invariants that are true of a first event by
 * construction rather than by policy.
 *
 * TWO CONSTANTS ARE TAKEN FROM THE DOMAIN EVENT, deliberately: the ALIAS and the
 * durable timestamp format. Both are declared there as the storage contract, and a
 * second copy here would be a second source of truth for the one thing that must
 * never drift.
 *
 * IDENTIFIER FORMAT IS NOT RE-VALIDATED. `payment_outbox.event_id` and
 * `aggregate_uuid` are `uuid` columns, so PostgreSQL has already refused anything
 * malformed; requiring the payload to EQUAL them transitively gives the same
 * guarantee with one comparison instead of a duplicated regex that could drift from
 * the Domain's.
 */
final readonly class PaymentInitiatedEnvelope
{
    /** The only durable schema version this consumer decodes. */
    public const int SCHEMA_VERSION = 1;

    /**
     * A payment's first event is always aggregate version 1.
     *
     * True BY CONSTRUCTION rather than by policy: PaymentAggregate::initiate() is
     * the only producer, it refuses an already-initiated aggregate, and
     * stored_events enforces one row per (aggregate, version). A later lifecycle
     * event will carry a higher version and will bring its own envelope type; this
     * one would be decoding something it does not understand.
     */
    public const int FIRST_AGGREGATE_VERSION = 1;

    /**
     * The durable payload key set, exactly.
     *
     * ASSERTED AS A SET, not with a series of isset() checks: an EXTRA key is as
     * much a schema disagreement as a missing one, and the version that only
     * checked for the keys it wanted would decode a v2 payload as though it were
     * v1 and project a partial payment.
     *
     * @var list<string>
     */
    private const array PAYLOAD_KEYS = [
        'eventId', 'paymentId', 'merchantId', 'amountMinor', 'currency', 'occurredAt',
    ];

    /** ISO 4217 alphabetic, structural only — WHICH currencies are allowed is a Domain rule. */
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
     * Decode one locked `payment_outbox` row, or refuse it.
     *
     * Takes an ARRAY rather than the stdClass the builder returns, so a missing
     * column is a null this method reports rather than an undefined-property
     * warning at the first read.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromOutboxRow(array $row): self
    {
        $eventId = self::string($row, 'event_id');

        // THE ALIAS, NEVER THE CLASS NAME. event_type carries the stable alias
        // precisely so the namespace can move without rewriting history, so this
        // compares against the constant that declares it.
        $alias = self::string($row, 'event_type');

        if ($alias !== PaymentInitiated::ALIAS) {
            throw ProjectionEnvelopeRejected::unsupportedAlias($eventId, $alias);
        }

        $schemaVersion = self::integer($row, 'schema_version');

        // REFUSED, NOT UPGRADED. A consumer that guessed at an unknown version
        // would write a read row from fields it had not agreed to; refusing keeps
        // the envelope retryable and visible until a decoder for that version
        // exists.
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedSchemaVersion($eventId, $schemaVersion);
        }

        $aggregateVersion = self::integer($row, 'aggregate_version');

        if ($aggregateVersion !== self::FIRST_AGGREGATE_VERSION) {
            throw ProjectionEnvelopeRejected::unsupportedAggregateVersion($eventId, $aggregateVersion);
        }

        $payload = self::payload($eventId, $row);

        // IDENTITY AGREEMENT BETWEEN PAYLOAD AND COLUMNS, in both directions that
        // exist. The columns are what the relay routed on and what this consumer
        // locked; the payload is what would be projected. An envelope where the two
        // disagree describes two different payments, and picking either one would
        // be a guess.
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
     * Decode one canonical `stored_events` row, or refuse it.
     *
     * THE SECOND ENTRY POINT, ADDED SO THERE IS STILL ONE OWNER OF "WHAT A VALID
     * PaymentInitiated PAYLOAD IS". The verification audit reads canonical history
     * rather than envelopes, and a second decoder written beside this one would be
     * a second definition of the payload contract - the exact drift this class's
     * docblock exists to prevent. Everything below the entry point is shared.
     *
     * WHAT DIFFERS FROM AN ENVELOPE, AND WHY. `stored_events` has no `occurred_at`
     * column and no event-id column: business time and the durable event id live
     * inside `event_properties`, which is the SOURCE the outbox row was derived
     * from. So there is no column to cross-check them against here, and the two
     * comparisons fromOutboxRow() makes for those fields have no canonical
     * counterpart. Every other check is identical, including the one that matters
     * most on this path - the payload's `paymentId` must equal the row's own
     * `aggregate_uuid`, which is a real disagreement the canonical row can exhibit.
     *
     * THE REFUSAL MESSAGES SAY "outbox event". They are shared with the path above
     * and are not surfaced by the audit, which reports only
     * ProjectionEnvelopeRejected::$category; the identifier they carry is the row's
     * aggregate uuid, which is a real durable identifier either way.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromStoredEventRow(array $row): self
    {
        // THE AGGREGATE UUID IS THE IDENTIFIER FOR REFUSALS, because the durable
        // event id is inside the payload being validated and is not trustworthy
        // until that validation has passed.
        $paymentId = self::string($row, 'aggregate_uuid');

        $alias = self::string($row, 'event_class');

        if ($alias !== PaymentInitiated::ALIAS) {
            throw ProjectionEnvelopeRejected::unsupportedAlias($paymentId, $alias);
        }

        // event_version is the vendor column that carries what the envelope calls
        // schema_version; T6 writes one from the other, so the same constant bounds
        // both.
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

        // PARSED IN THE DURABLE FORMAT AND RE-FORMATTED, the same two rejections
        // occurredAt() makes; only its third comparison - against a column - has no
        // counterpart here. Passing the payload value as both arguments would make
        // that comparison trivially true and read as though a check were happening.
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
     * The decoded payload, with every key present and every type checked.
     *
     * @param  array<string, mixed>  $row
     * @return array{eventId: string, paymentId: string, merchantId: int, amountMinor: int, currency: string, occurredAt: string}
     */
    private static function payload(string $eventId, array $row): array
    {
        $raw = $row['payload'] ?? null;

        // jsonb comes back as a STRING through PDO, not as an array. `true` for
        // associative decoding, because an object payload would otherwise decode to
        // stdClass and every key read would be `mixed` again.
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'payload is not a JSON object');
        }

        $keys = array_keys($decoded);
        sort($keys);
        $expected = self::PAYLOAD_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            // ONLY THE EXPECTED KEYS ARE NAMED, never the ones that arrived. An
            // earlier version interpolated $keys and justified it as "module-owned
            // schema" — but those are the keys of the INCOMING jsonb, which is
            // exactly the caller-influenced data this message must not carry into a
            // retained failed-job record. Nothing merchant-controlled can reach them
            // today, since PaymentOutboxWriter writes only the six promoted
            // properties; stating an invariant the code does not enforce is how that
            // stops being true quietly. The expected set alone diagnoses it.
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

        // is_int, NOT is_numeric: "1250" and 1250.0 are both numeric and neither is
        // what json_decode produces from a durable integer. Accepting them would
        // hide a payload that had been through a lossy round trip.
        foreach (['merchantId', 'amountMinor'] as $key) {
            if (! is_int($decoded[$key])) {
                throw ProjectionEnvelopeRejected::malformedPayload($eventId, "{$key} is not an integer");
            }
        }

        // POSITIVE, which is structural rather than a business limit. A merchant id
        // addresses a bigserial primary key and an amount in minor units describes
        // money; zero or negative is not a small payment, it is a corrupt one. The
        // UPPER bound on the amount and the set of accepted currencies are Domain
        // policy and are deliberately not repeated here.
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

    /**
     * Business time, parsed from the payload and cross-checked against the column.
     *
     * THE PAYLOAD IS THE SOURCE and `occurred_at` is a derived column: T6 wrote the
     * column FROM the event, so the payload is where the microseconds are
     * authoritative. Parsing it in the exact durable format is what keeps
     * `payment_details.initiated_at` a faithful copy of business time rather than
     * of processing time.
     *
     * THE COLUMN IS STILL COMPARED, as INSTANTS rather than as strings. The two are
     * written by one statement from one value, so a disagreement means the envelope
     * was corrupted or edited after the fact — the one condition neither value can
     * reveal alone. Instants, because the column comes back in PostgreSQL's own
     * rendering and at whatever offset the session displays; only the point in time
     * is the contract.
     */
    private static function occurredAt(string $eventId, string $payloadValue, string $columnValue): CarbonImmutable
    {
        // BOTH FAILURE SHAPES, BECAUSE Carbon HAS TWO. MEASURED: with strict mode on
        // — the default — createFromFormat THROWS InvalidFormatException, and with
        // it off the same call returns NULL. The plain DateTimeImmutable the Domain
        // event uses returns `false` and does neither. A `=== false` check copied
        // from there compiles, reads correctly, and can never fire.
        try {
            $parsed = CarbonImmutable::createFromFormat(PaymentInitiated::OCCURRED_AT_FORMAT, $payloadValue);
        } catch (Throwable) {
            $parsed = null;
        }

        // The re-format comparison is the third rejection and a separate one: it is
        // what refuses a date that does not exist, because createFromFormat accepts
        // 2026-02-30 and rolls it forward rather than failing.
        if ($parsed === null || $parsed->format(PaymentInitiated::OCCURRED_AT_FORMAT) !== $payloadValue) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurredAt is not in the durable format');
        }

        try {
            $column = CarbonImmutable::parse($columnValue);
        } catch (Throwable) {
            // Unreachable from a `timestamptz` column, and refused rather than
            // suppressed so the conversion is honest instead of assumed.
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurred_at column is not a timestamp');
        }

        if (! $parsed->equalTo($column)) {
            throw ProjectionEnvelopeRejected::malformedPayload($eventId, 'occurredAt disagrees with occurred_at');
        }

        return $parsed;
    }

    /**
     * Business time from a canonical payload, with no column to cross-check.
     *
     * Both Carbon failure shapes and the re-format comparison behave exactly as
     * occurredAt() records; see that method for why `=== false` cannot be used and
     * why the re-format is a separate rejection rather than belt and braces.
     */
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
            // NOT VALIDATION — a type conversion made honest. Every column read
            // this way is NOT NULL in the schema, so this is unreachable from a row
            // the consumer's own query can return, and it refuses loudly rather
            // than projecting from an empty string.
            throw ProjectionEnvelopeRejected::malformedEnvelope($column);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        // is_numeric rather than is_int: PostgreSQL integers arrive as strings
        // through PDO depending on the driver's emulation setting, and that is a
        // transport detail rather than a schema disagreement. The payload above is
        // held to the stricter rule because it is durable JSON, where the type IS
        // the schema.
        if (! is_numeric($value)) {
            throw ProjectionEnvelopeRejected::malformedEnvelope($column);
        }

        return (int) $value;
    }
}
