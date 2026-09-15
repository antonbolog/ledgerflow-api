<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Application\Query\PaymentNotFound;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Query\PendingPayment;
use Modules\Payments\Application\Query\ProjectedPayment;
use Modules\Payments\Application\Query\ProjectionRebuilding;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Override;

final readonly class DatabasePaymentQuery implements PaymentQuery
{
    public function __construct(
        private ConnectionInterface $connection,
        private ProjectionGate $gate,
    ) {}

    #[Override]
    public function findOwned(int $merchantId, string $paymentId): ProjectedPayment|PendingPayment
    {
        // Refuse before PostgreSQL: its 22P02 would make malformed ids distinguishable from unknown ones.
        if (! Str::isUuid($paymentId)) {
            throw PaymentNotFound::forRequestedPayment();
        }

        /** @var ProjectedPayment|PendingPayment $found */
        $found = $this->connection->transaction(
            fn (): ProjectedPayment|PendingPayment => $this->lookUp($merchantId, $paymentId),
        );

        return $found;
    }

    private function lookUp(int $merchantId, string $paymentId): ProjectedPayment|PendingPayment
    {
        // The gate check must be the first statement in this transaction (ADR 0005).
        if (! $this->gate->inspectPaymentDetails()->isOpen()) {
            throw ProjectionRebuilding::forRequestedPayment();
        }

        $projected = $this->projected($merchantId, $paymentId);

        if ($projected !== null) {
            return $projected;
        }

        if ($this->hasOwnedReceipt($merchantId, $paymentId)) {
            return new PendingPayment($paymentId);
        }

        throw PaymentNotFound::forRequestedPayment();
    }

    private function projected(int $merchantId, string $paymentId): ?ProjectedPayment
    {
        /** @var object{payment_id: string, amount_minor: int|string, currency: string, status: string, initiated_at: string}|null $row */
        $row = $this->connection->table('payment_details')
            ->where('payment_id', $paymentId)
            ->where('merchant_id', $merchantId)
            // Named columns only, so a column added to payment_details cannot leak into a response.
            ->first(['payment_id', 'amount_minor', 'currency', 'status', 'initiated_at']);

        if ($row === null) {
            return null;
        }

        return new ProjectedPayment(
            paymentId: $row->payment_id,
            amountMinor: (int) $row->amount_minor,
            currency: $row->currency,
            status: $row->status,
            initiatedAt: self::canonicalInstant($row->initiated_at),
        );
    }

    // exists(), not a row fetch: stored initiation response bytes must not reach this read path.
    private function hasOwnedReceipt(int $merchantId, string $paymentId): bool
    {
        return $this->connection->table('payment_idempotency_receipts')
            ->where('merchant_id', $merchantId)
            ->where('payment_id', $paymentId)
            ->exists();
    }

    private static function canonicalInstant(string $stored): string
    {
        return CarbonImmutable::parse($stored)->utc()->format('Y-m-d\TH:i:s.uP');
    }
}
