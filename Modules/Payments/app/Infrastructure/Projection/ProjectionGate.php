<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

// Runs inside the caller's transaction on the caller's connection, never blocking traffic. Either half closes
// the gate: the lock misses a rebuild whose backend died, the row misses a rebuild not yet recorded (ADR 0005).
final readonly class ProjectionGate
{
    public const int CLASS_ID = 7011;

    public const int PAYMENT_DETAILS_OBJECT_ID = 1;

    public const string PAYMENT_DETAILS = PaymentProjectionConsumer::PROJECTOR_NAME;

    public const string READY = 'ready';

    public const string REBUILDING = 'rebuilding';

    public function __construct(private ConnectionInterface $connection) {}

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

    public function inspectPaymentDetails(): ProjectionGateState
    {
        return $this->inspect(self::PAYMENT_DETAILS, self::PAYMENT_DETAILS_OBJECT_ID);
    }

    private function acquireShared(int $objectId): bool
    {
        // ::int in SQL: PDO may return a boolean as 'f', which is truthy in PHP.
        /** @var object{acquired: int|string}|null $row */
        $row = $this->connection->selectOne(sprintf(
            'select pg_try_advisory_xact_lock_shared(%d, %d)::int as acquired',
            self::CLASS_ID,
            $objectId,
        ));

        return $row !== null && (int) $row->acquired === 1;
    }

    // Fail closed: a missing row or any state other than ready closes the gate.
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
