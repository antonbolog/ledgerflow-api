<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeMissing;
use Modules\Payments\Infrastructure\Projection\ProjectionEnvelopeRejected;
use Modules\Payments\Infrastructure\Projection\ProjectionFailureClassification;
use Modules\Payments\Tests\Support\StringCodePdoException;

/*
|--------------------------------------------------------------------------
| Which failures may consume the poison budget
|--------------------------------------------------------------------------
|
| THE WHOLE RULE SET AS A TABLE, framework-free and database-free, because the
| classification is a pure function over a Throwable. The real-PostgreSQL
| representatives — an owned unique violation, a lock timeout, a failed metadata
| write — live in the Integration suite, where the exceptions are produced by the
| server rather than constructed. Both are needed: this file pins the RULES, that
| one pins that the rules match what PostgreSQL actually raises.
|
| THE COUNTABLE AND NON-COUNTABLE SETS ARE ASSERTED AS SETS. A test per case would
| let a new category be added to the classification and simply never be tested;
| the closed-set assertion at the bottom fails until the table below mentions it.
|
*/

/** A QueryException-shaped failure carrying a SQLSTATE, as the driver builds them. */
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
    // The module's own refusals carry their category already; the classification
    // must pass it through rather than re-deriving it.
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

    // Owned constraints. A violation of one of these IS contradictory history: the
    // names are pinned by this module's migration and asserted by the schema test,
    // so matching on them is matching on a contract this repository owns.
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

    // NOT A DATABASE FAILURE, so by elimination it came from the projector's own
    // code and every redelivery reproduces it.
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
    // There is no row to charge — that IS the failure — so metadata would be an
    // orphan describing a delivery for an envelope PostgreSQL does not know.
    'a missing envelope' => [fn () => new ProjectionEnvelopeMissing('e')],

    // Concurrency, which the write path already owns the judgement on. Waiting or
    // retrying resolves all three, so none of them is attributable to the payload.
    'a deadlock' => [fn () => classifiedFailure('40P01')],
    'a serialization failure' => [fn () => classifiedFailure('40001')],
    'a lock timeout' => [fn () => classifiedFailure('55P03')],

    // query_canceled, which is what statement_timeout raises. Charging it would let
    // a slow database exhaust healthy envelopes.
    'a cancelled statement' => [fn () => classifiedFailure('57014')],

    // CLASS 08 IN ALL ITS FORMS, read as a class rather than as a list of members:
    // every 08xxx means the client lost the server.
    'a connection exception' => [fn () => classifiedFailure('08000')],
    'a connection failure' => [fn () => classifiedFailure('08006')],
    'a failed connection attempt' => [fn () => classifiedFailure('08001')],
    'a broken protocol' => [fn () => classifiedFailure('08P01')],

    // A database error we cannot name, on no constraint we own. Not charged: the
    // unknown remainder of database errors belongs with the transient ones, because
    // the alternative is exhausting healthy envelopes on conditions nobody modelled.
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
    // THE CLOSED SET, so a new category cannot be introduced without this file
    // acknowledging it. Every value the classification can return must appear here,
    // and every value here must be persistable in last_projection_error, which is
    // varchar-free text but is read by operators as a fixed vocabulary.
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

    // The one category that is not a refusal type: the deterministic projector bug
    // that could not be named more precisely.
    expect(ProjectionFailureClassification::HANDLER_FAILED)->toBe('projection_handler_failed');
});

it('carries no payload value or raw server text into any category it returns', function (): void {
    // SENTINEL. The category is what reaches last_projection_error, which is durable
    // and read by operators. It must be one of the fixed constants and never a
    // fragment of whatever the driver or the payload contained.
    $hostile = classifiedFailure(
        '23505',
        'duplicate key value violates unique constraint "payment_details_pkey" DETAIL: Key (payment_id)=(SECRET-VALUE)',
    );

    $category = ProjectionFailureClassification::categoryFor($hostile);

    expect($category)->toBe(ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY)
        ->and($category)->not->toContain('SECRET-VALUE')
        ->and($category)->not->toContain('DETAIL');
});
