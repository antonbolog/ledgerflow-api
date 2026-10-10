<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Closure;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Override;

/**
 * Lets a competitor act at an exact point of a real initiation: after its first winner lookup, or instead of its first
 * reservation. Callbacks run on the initiation's own connection, so a competitor must use another one.
 */
final class RacingReceipts implements IdempotencyReceipts
{
    public int $lookups = 0;

    public int $reservations = 0;

    public ?Closure $afterFirstLookup = null;

    public ?Closure $beforeFirstReservation = null;

    public function __construct(private readonly IdempotencyReceipts $inner) {}

    #[Override]
    public function findArbitrationCandidates(int $merchantId, string $intentId, string $idempotencyKeyHash): array
    {
        $candidates = $this->inner->findArbitrationCandidates($merchantId, $intentId, $idempotencyKeyHash);

        if (++$this->lookups === 1 && $this->afterFirstLookup !== null) {
            ($this->afterFirstLookup)();
        }

        return $candidates;
    }

    #[Override]
    public function reserveIntent(PaymentInitiationContext $context): bool
    {
        if (++$this->reservations === 1 && $this->beforeFirstReservation !== null) {
            ($this->beforeFirstReservation)();
        }

        return $this->inner->reserveIntent($context);
    }
}
