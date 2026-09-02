<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projection\PaymentInitiatedEnvelope;
use Modules\Payments\Infrastructure\Verification\VerificationEnvelope;

/*
|--------------------------------------------------------------------------
| Envelope comparison
|--------------------------------------------------------------------------
|
| A PURE COMPARISON, TESTED WITHOUT A DATABASE, and that is the point of testing
| it here rather than only through the audit. Both columns the verifier reads are
| jsonb, so PostgreSQL normalises key order before either value reaches PHP — an
| integration test for the key-order rule could therefore never fail, whatever the
| comparison did. These cases drive the comparison directly, so the rule is proved
| rather than provided by the column type.
|
*/

const VERIFICATION_OCCURRED_AT = '2026-08-21T10:11:12.123456+00:00';

const VERIFICATION_PAYMENT_ID = '0199a3c1-1111-7000-8000-000000000001';

const VERIFICATION_EVENT_ID = '0199a3c1-2222-7000-8000-000000000002';

/** @return array<string, mixed> */
function verificationCanonicalPayload(array $overrides = []): array
{
    return array_merge([
        'eventId' => VERIFICATION_EVENT_ID,
        'paymentId' => VERIFICATION_PAYMENT_ID,
        'merchantId' => 7,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => VERIFICATION_OCCURRED_AT,
    ], $overrides);
}

function verificationCanonicalEvent(array $payloadOverrides = []): PaymentInitiatedEnvelope
{
    return PaymentInitiatedEnvelope::fromStoredEventRow([
        'aggregate_uuid' => VERIFICATION_PAYMENT_ID,
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => PaymentInitiated::ALIAS,
        'event_properties' => json_encode(verificationCanonicalPayload($payloadOverrides)),
    ]);
}

function verificationEnvelope(array $overrides = []): VerificationEnvelope
{
    return VerificationEnvelope::fromRow((object) array_merge([
        'event_type' => PaymentInitiated::ALIAS,
        'schema_version' => 1,
        'aggregate_uuid' => VERIFICATION_PAYMENT_ID,
        'aggregate_version' => 1,
        'occurred_at' => VERIFICATION_OCCURRED_AT,
        'payload' => json_encode(verificationCanonicalPayload()),
        'projection_completed_at' => null,
        'exhausted_at' => null,
    ], $overrides));
}

it('finds nothing wrong with an envelope that matches canonical history', function (): void {
    expect(verificationEnvelope()->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe([]);
});

it('treats a payload whose keys arrive in another order as equal', function (): void {
    // SEMANTIC, NOT TEXTUAL. An operator sent to repair a payload that describes
    // exactly the same object would find nothing to fix.
    $reordered = array_reverse(verificationCanonicalPayload(), preserve_keys: true);

    expect(verificationEnvelope(['payload' => json_encode($reordered)])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe([]);
});

it('still reports a payload value that actually changed', function (): void {
    // THE FAILURE PATH OF THE SAME COMPARISON. Without it, "key order is ignored"
    // would be indistinguishable from "the payload is never compared".
    expect(verificationEnvelope([
        'payload' => json_encode(verificationCanonicalPayload(['amountMinor' => 9_999])),
    ])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe(['payload']);
});

it('refuses a payload whose integer arrived as a string', function (): void {
    // A LOSSY ROUND TRIP IS CORRUPTION, not a rendering difference: 1250 and
    // "1250" are the same number and a different durable payload.
    expect(verificationEnvelope([
        'payload' => json_encode(verificationCanonicalPayload(['amountMinor' => '1250'])),
    ])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe(['payload']);
});

it('treats a withheld payload as a mismatch rather than as agreement', function (): void {
    // null means the value was over the inspection cap and never transferred. The
    // verifier turns that into an incomplete audit; what must never happen is for
    // an uninspected payload to compare equal.
    expect(verificationEnvelope(['payload' => null])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe(['payload']);
});

it('compares event time as an instant rather than as a rendering', function (): void {
    // The same instant, spelled at a different offset. A string comparison would
    // report a mismatch that does not exist.
    expect(verificationEnvelope(['occurred_at' => '2026-08-21 12:11:12.123456+02'])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe([]);
});

it('detects microsecond drift in event time', function (): void {
    expect(verificationEnvelope(['occurred_at' => '2026-08-21T10:11:12.123457+00:00'])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe(['occurred_at']);
});

it('names every identity column that disagrees with canonical history', function (): void {
    expect(verificationEnvelope([
        'event_type' => 'payments.payment_settled',
        'schema_version' => 2,
        'aggregate_uuid' => '0199a3c1-3333-7000-8000-000000000003',
        'aggregate_version' => 4,
    ])->mismatchedFields(
        verificationCanonicalEvent(),
        (string) json_encode(verificationCanonicalPayload()),
    ))->toBe(['event_type', 'schema_version', 'aggregate_uuid', 'aggregate_version']);
});
