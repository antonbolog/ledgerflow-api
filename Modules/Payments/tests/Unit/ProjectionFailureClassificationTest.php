<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeMissing;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureClassification;
use Modules\Payments\Tests\Support\StringCodePdoException;

function classifiedFailure(string $sqlState, string $message = 'server said something'): Throwable
{
    return new StringCodePdoException($message, $sqlState);
}

it('charges a confirmed deterministic failure under its own fixed category', function (
    Throwable $failure,
    string $expected,
): void {
    expect(ProjectionFailureClassification::categoryFor($failure))->toBe($expected);
})->with([
    'unsupported alias' => [
        fn () => ProjectionEnvelopeRejected::unsupportedAlias('e', 'payments.other'),
        ProjectionEnvelopeRejected::UNSUPPORTED_ALIAS,
    ],
    'unsupported schema version' => [
        fn () => ProjectionEnvelopeRejected::unsupportedSchemaVersion('e', 2),
        ProjectionEnvelopeRejected::UNSUPPORTED_SCHEMA_VERSION,
    ],
    'unsupported aggregate version' => [
        fn () => ProjectionEnvelopeRejected::unsupportedAggregateVersion('e', 2),
        ProjectionEnvelopeRejected::UNSUPPORTED_AGGREGATE_VERSION,
    ],
    'malformed payload' => [
        fn () => ProjectionEnvelopeRejected::malformedPayload('e', 'reason'),
        ProjectionEnvelopeRejected::MALFORMED_PAYLOAD,
    ],
    'malformed envelope' => [
        fn () => ProjectionEnvelopeRejected::malformedEnvelope('column'),
        ProjectionEnvelopeRejected::MALFORMED_ENVELOPE,
    ],
    'contradictory identity' => [
        fn () => ProjectionEnvelopeRejected::contradictoryIdentity('e', 'paymentId'),
        ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
    ],
    'read row missing' => [
        fn () => ProjectionEnvelopeRejected::readRowMissing('e', 'p'),
        ProjectionEnvelopeRejected::READ_ROW_MISSING,
    ],
    'read row mismatch' => [
        fn () => ProjectionEnvelopeRejected::readRowMismatch('e', 'p'),
        ProjectionEnvelopeRejected::READ_ROW_MISMATCH,
    ],

    'unique violation on the read row primary key' => [
        fn () => classifiedFailure(
            '23505',
            'duplicate key value violates unique constraint "payment_details_pkey"',
        ),
        ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
    ],
    'unique violation on the source event key' => [
        fn () => classifiedFailure(
            '23505',
            'duplicate key value violates unique constraint "payment_details_source_event_id_unique"',
        ),
        ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
    ],
    'foreign key violation on the owning merchant' => [
        fn () => classifiedFailure(
            '23503',
            'violates foreign key constraint "payment_details_merchant_id_foreign"',
        ),
        ProjectionFailureClassification::HANDLER_FAILED,
    ],

    'an unexpected non-database exception' => [
        fn () => new RuntimeException('the projector broke'),
        ProjectionFailureClassification::HANDLER_FAILED,
    ],
    'a TypeError from the projector' => [
        fn () => new TypeError('argument type'),
        ProjectionFailureClassification::HANDLER_FAILED,
    ],
]);

it('charges nothing for a failure that says nothing about the envelope', function (Throwable $failure): void {
    expect(ProjectionFailureClassification::categoryFor($failure))->toBeNull();
})->with([
    'a missing envelope' => [fn () => new ProjectionEnvelopeMissing('e')],

    'a deadlock' => [fn () => classifiedFailure('40P01')],
    'a serialization failure' => [fn () => classifiedFailure('40001')],
    'a lock timeout' => [fn () => classifiedFailure('55P03')],

    'a cancelled statement' => [fn () => classifiedFailure('57014')],

    'a connection exception' => [fn () => classifiedFailure('08000')],
    'a connection failure' => [fn () => classifiedFailure('08006')],
    'a failed connection attempt' => [fn () => classifiedFailure('08001')],
    'a broken protocol' => [fn () => classifiedFailure('08P01')],

    'an unrecognised database error' => [fn () => classifiedFailure('53200', 'out of memory')],
    'a check violation on a constraint we do not own' => [
        fn () => classifiedFailure('23514', 'violates check constraint "some_other_table_check"'),
    ],
    'a unique violation on a constraint we do not own' => [
        fn () => classifiedFailure(
            '23505',
            'duplicate key value violates unique constraint "stored_events_aggregate_uuid_aggregate_version_unique"',
        ),
    ],
]);

it('keeps the countable category set closed', function (): void {
    $reflection = new ReflectionClass(ProjectionEnvelopeRejected::class);

    $rejectionCategories = array_values(array_filter(
        $reflection->getConstants(),
        fn (mixed $value): bool => is_string($value) && str_starts_with($value, 'projection_'),
    ));

    expect($rejectionCategories)->toEqualCanonicalizing([
        'projection_alias_unsupported',
        'projection_schema_version_unsupported',
        'projection_aggregate_version_unsupported',
        'projection_payload_malformed',
        'projection_envelope_malformed',
        'projection_identity_contradictory',
        'projection_read_row_missing',
        'projection_read_row_mismatch',
    ]);

    expect(ProjectionFailureClassification::HANDLER_FAILED)->toBe('projection_handler_failed');
});

it('carries no payload value or raw server text into any category it returns', function (): void {
    $hostile = classifiedFailure(
        '23505',
        'duplicate key value violates unique constraint "payment_details_pkey" DETAIL: Key (payment_id)=(SECRET-VALUE)',
    );

    $category = ProjectionFailureClassification::categoryFor($hostile);

    expect($category)->toBe(ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY)
        ->and($category)->not->toContain('SECRET-VALUE')
        ->and($category)->not->toContain('DETAIL');
});
