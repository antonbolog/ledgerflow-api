<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The Payments projection gate, as two statements on the caller's connection.
 *
 *     [caller has already run BEGIN and its own SET LOCAL bounds]
 *     SELECT pg_try_advisory_xact_lock_shared(class, object)   <- half one
 *     SELECT state FROM payment_projection_controls WHERE ...  <- half two
 *
 * IT OPENS NO TRANSACTION AND OWNS NO CONNECTION LIFECYCLE. Every caller
 * already has both: the relay, the consumer, the query adapter and the write
 * path each open one explicit transaction on the same default Connection object.
 * The gate is prepended INSIDE that transaction, on that connection, which is
 * what makes the shared lock and the work it protects report one backend pid.
 * A gate that opened its own transaction, or resolved its own connection, would
 * protect a different session from the one doing the work.
 *
 * BOTH HALVES ARE CHECKED, AND EITHER CLOSES THE GATE. They catch different
 * failures and neither is redundant:
 *
 *   - The LOCK alone misses a rebuild whose backend went away. PostgreSQL
 *     releases a session-level advisory lock when that happens, so the lock is
 *     free while payment_details is still half-cleared. The durable row is the
 *     only thing left saying so.
 *   - The ROW alone misses the window before a rebuild has written it, and
 *     cannot serialize concurrent work at all.
 *
 * NON-BLOCKING, DELIBERATELY. pg_try_advisory_xact_lock_shared answers
 * immediately, so no traffic path can be made to wait by a rebuild. Only the
 * rebuild's own exclusive acquisition blocks, and it bounds itself with
 * lock_timeout. A blocking shared acquisition here would let one rebuild turn
 * every request, relay pass and delivery into a timeout.
 *
 * THE TWO-INTEGER FORM, NOT THE ONE-BIGINT FORM. pg_locks then reports the
 * halves in classid/objid with objsubid = 2, so evidence can assert the exact
 * key rather than a truncated bigint, and an unrelated advisory lock elsewhere
 * in this database is distinguishable rather than merely improbable.
 *
 * See ADR 0005 for the decision record and
 * docs/specifications/payments/verification-and-rebuild.md for the contract.
 */
final readonly class ProjectionGate
{
    /**
     * The Payments advisory namespace.
     *
     * AN EXPLICIT CONSTANT, NOT A HASH OF ANYTHING. A hashed derivation from the
     * projection name would buy extensibility nothing needs yet and would turn a
     * collision with an unrelated advisory key into a silent event rather than a
     * reviewable one. Adding a projection later is one constant and one seeded
     * row, both of which a reader can check by eye.
     */
    public const int CLASS_ID = 7011;

    /** The `payment_details` projection's object identifier within that namespace. */
    public const int PAYMENT_DETAILS_OBJECT_ID = 1;

    /** The durable projector name, matching payment_projector_receipts.projector_name. */
    public const string PAYMENT_DETAILS = PaymentProjectionConsumer::PROJECTOR_NAME;

    /** The only state in which derived state may be read or written. */
    public const string READY = 'ready';

    /** A rebuild owns the projection; its read model may be partial. */
    public const string REBUILDING = 'rebuilding';

    /**
     * ConnectionInterface, NOT the concrete Connection. Every method this class
     * calls - transactionLevel(), selectOne() - is declared on the interface, and
     * every one of T11's four traffic-path callers already resolves the SAME
     * default Connection object through it (see PaymentsServiceProvider), so
     * nothing is lost by depending on the narrower type. What IS gained: a Slice 2
     * caller under Unit test can substitute a lightweight ConnectionInterface fake
     * instead of the concrete Connection, which Laravel does not offer a light
     * fake for.
     */
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * Check the gate for one projection, inside the caller's open transaction.
     *
     * THE TRANSACTION GUARD IS CORRECTNESS, NOT DEFENCE. A transaction-scoped
     * advisory lock taken outside a transaction is released at the end of the
     * statement that took it, so the lock would be gone before the caller's first
     * real query ran and the check would report `Open` while protecting nothing.
     * That failure is silent and total, which is exactly the kind that must
     * refuse loudly instead.
     *
     * It is the mirror of the guard DatabaseProjectionVerifier and
     * DatabaseOutboxRepair carry: they refuse a transaction they do not own,
     * because they must own the isolation level and the bounds; this one requires
     * a transaction it does not own, because the caller owns the lifecycle and the
     * lock must live exactly as long as it.
     */
    public function inspect(string $projection, int $objectId): ProjectionGateState
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new RuntimeException(
                'The Payments projection gate must be checked inside the caller\'s open transaction. '
                .'A transaction-scoped advisory lock taken outside one is released at the end of its own '
                .'statement, so the check would report an open gate while holding nothing.'
            );
        }

        if (! $this->acquireShared($objectId)) {
            return ProjectionGateState::ClosedByRebuildLock;
        }

        return $this->readControlState($projection);
    }

    /** The gate for the one projection Payments has today. */
    public function inspectPaymentDetails(): ProjectionGateState
    {
        return $this->inspect(self::PAYMENT_DETAILS, self::PAYMENT_DETAILS_OBJECT_ID);
    }

    /**
     * Take the shared half of the gate, or report that a rebuild holds it.
     *
     * ::int IN SQL RATHER THAN A PHP CAST OF THE RAW VALUE. PDO's pgsql driver is
     * free to hand a boolean back as the string 'f', and 'f' is truthy in PHP, so
     * a refused lock would read as granted. The conversion happens where
     * PostgreSQL can be held to it.
     *
     * The key halves are interpolated as integers rather than bound: they are
     * class constants and a caller-supplied object id already narrowed to int by
     * the signature, and a bound parameter would make the statement's plan cache
     * key vary for no benefit.
     */
    private function acquireShared(int $objectId): bool
    {
        /** @var object{acquired: int|string}|null $row */
        $row = $this->connection->selectOne(sprintf(
            'select pg_try_advisory_xact_lock_shared(%d, %d)::int as acquired',
            self::CLASS_ID,
            $objectId,
        ));

        // A NULL ROW IS NOT AN OPEN GATE. `select` over a set-returning-free
        // expression always yields one row, so this is unreachable from a healthy
        // connection; it resolves to "not acquired" rather than to an assumption,
        // because the one thing this method must never do is report a lock it
        // does not hold.
        return $row !== null && (int) $row->acquired === 1;
    }

    /**
     * The durable half.
     *
     * RAW SQL, NOT THE QUERY BUILDER: table()->where()->value() compiles to one
     * select statement, exactly as this selectOne() does, so nothing about the
     * gate's behaviour changes - only the type this class depends on narrows to
     * what ConnectionInterface actually declares.
     *
     * A MISSING ROW IS CLOSED. The migration seeds one row per projection, so its
     * absence is damage rather than a fresh install, and answering `ready` for a
     * projection whose control state cannot be read would defeat the whole point
     * of having durable state.
     *
     * ANY state other than `ready` closes the gate, rather than only
     * `rebuilding`. The CHECK constraint already restricts the column to two
     * values; matching on `ready` alone means a third value added later fails
     * closed by default instead of silently reading as open.
     */
    private function readControlState(string $projection): ProjectionGateState
    {
        /** @var object{state: string}|null $row */
        $row = $this->connection->selectOne(
            'select state from payment_projection_controls where projection = ?',
            [$projection],
        );

        if ($row === null) {
            return ProjectionGateState::ClosedByMissingControlRow;
        }

        return $row->state === self::READY
            ? ProjectionGateState::Open
            : ProjectionGateState::ClosedByControlState;
    }
}
