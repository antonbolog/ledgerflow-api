<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Throwable;

/**
 * Which failures may consume an envelope's five-delivery poison budget.
 *
 * ONE QUESTION, ASKED IN ONE PLACE: given the exception that ended a delivery,
 * return the fixed safe category to charge it under, or null to charge nothing.
 * It is deliberately a pure function over a Throwable — no connection, no clock,
 * no state — so the whole rule set is testable as a table without a database.
 *
 * THE DEFAULT IS "DO NOT CHARGE", and that asymmetry is the design. A failure
 * wrongly counted walks a healthy envelope towards `exhausted_at`, where only an
 * operator can retrieve it; a failure wrongly NOT counted leaves the envelope
 * retryable and the relay redelivers it. The first loses a payment's read row
 * until someone notices, the second costs another delivery.
 *
 * DATABASE FAILURES ARE CLASSIFIED BY SQLSTATE AND OWNED CONSTRAINT IDENTITY,
 * NEVER BY MESSAGE TEXT. A driver rewording its message must not be able to move
 * a failure between budgets, which is exactly what substring matching would do.
 * The constraint NAMES below are ours — the schema migration pins them and the
 * schema test asserts them — so matching on them is matching on a contract this
 * repository owns rather than on a string a vendor chose.
 *
 * WHY AN UNRECOGNISED SQLSTATE IS NOT COUNTABLE while an unrecognised non-database
 * exception IS. A database error we cannot name might be transient — a resource
 * limit, a replication state, an operator's `pg_cancel_backend` — and the
 * classification rules already list connection loss, locks, serialisation and
 * cancellation as non-countable, so the unknown remainder belongs with them by
 * kind. An exception thrown by our own projector code is a deterministic bug in
 * the projector: every redelivery reproduces it, and letting it retry sixty times
 * an hour forever is worse than exhausting it for an operator to look at.
 */
final readonly class ProjectionFailureClassification
{
    /** The confirmed deterministic failure we could not name more precisely. */
    public const string HANDLER_FAILED = 'projection_handler_failed';

    /**
     * Owned constraints whose violation IS contradictory history.
     *
     * Each names a key this module's own migration creates. A violation means two
     * events disagree about one payment, or an envelope names a merchant that does
     * not exist — deterministic in both cases, and reproduced by every redelivery.
     *
     * @var array<string, string>
     */
    private const array OWNED_CONSTRAINTS = [
        'payment_details_pkey' => ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
        'payment_details_source_event_id_unique' => ProjectionEnvelopeRejected::CONTRADICTORY_IDENTITY,
        'payment_details_merchant_id_foreign' => self::HANDLER_FAILED,
        'payment_projector_receipts_pkey' => self::HANDLER_FAILED,
    ];

    /**
     * SQLSTATEs that say nothing about the envelope, so they charge nothing.
     *
     * The first three are RetryingAtomicTransaction's own retryable set, referenced
     * rather than copied: deadlock, serialisation failure and lock timeout are the
     * same three conditions whether the write path or the consumer meets them, and
     * a second hardcoded list here would be a second owner of that judgement.
     *
     * 57014 is query_canceled, which is what a `statement_timeout` raises — the
     * bound Horizon's configuration will add. Charging it would let a slow database
     * exhaust healthy envelopes.
     *
     * @return list<string>
     */
    private static function nonCountableStates(): array
    {
        return [...RetryingAtomicTransaction::RETRYABLE, '57014'];
    }

    /**
     * The category to charge this failure under, or null to charge nothing.
     *
     * ORDER MATTERS. The typed refusals are asked about first because they are the
     * module's own vocabulary and already carry their category; only then is the
     * exception examined as a database error; only then does the catch-all apply.
     */
    public static function categoryFor(Throwable $failure): ?string
    {
        // Its own category, already fixed and already safe. These are the envelope
        // and payload refusals plus the two read-row corruption states.
        if ($failure instanceof ProjectionEnvelopeRejected) {
            return $failure->category;
        }

        // NON-COUNTABLE BY TYPE. There is no outbox row to charge — that IS the
        // failure — so there is nowhere for metadata to go but an orphan row.
        if ($failure instanceof ProjectionEnvelopeMissing) {
            return null;
        }

        $sqlState = RetryingAtomicTransaction::sqlStateOf($failure);

        if ($sqlState === null) {
            // NOT A DATABASE FAILURE, so it came from our own projector code and is
            // deterministic by elimination.
            return self::HANDLER_FAILED;
        }

        if (in_array($sqlState, self::nonCountableStates(), true)) {
            return null;
        }

        // CLASS 08, connection exception, in all its forms. Read as a CLASS rather
        // than as a list of five members: every 08xxx means the client lost the
        // server, which is the textbook case of an outcome nobody can attribute to
        // the envelope.
        if (str_starts_with($sqlState, '08')) {
            return null;
        }

        return self::ownedConstraintCategory($failure);
    }

    /**
     * The category for a constraint this module owns, or null for anything else.
     *
     * THE CONSTRAINT NAME IS READ FROM THE MESSAGE, and that is a deliberate,
     * narrow exception to the no-message-matching rule rather than a breach of it.
     * PDO exposes the violated constraint only inside the driver's message text —
     * errorInfo carries the SQLSTATE but not the constraint — so there is no
     * structured channel to read instead. What keeps this honest is the DIRECTION
     * of the match: it asks whether one of OUR OWN pinned names appears, and
     * anything unrecognised falls through to null. A driver rewording its message
     * can therefore only ever make this charge LESS, never charge the wrong thing.
     */
    private static function ownedConstraintCategory(Throwable $failure): ?string
    {
        $message = $failure->getMessage();

        foreach (self::OWNED_CONSTRAINTS as $constraint => $category) {
            if (str_contains($message, $constraint)) {
                return $category;
            }
        }

        // A database error at a SQLSTATE we do not recognise, on no constraint we
        // own. Not charged: see the class docblock on why the unknown remainder of
        // database errors belongs with the transient ones.
        return null;
    }
}
