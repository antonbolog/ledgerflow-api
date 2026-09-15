<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\StoredIdempotencyReceipt;
use Override;

final readonly class DatabaseIdempotencyReceipts implements IdempotencyReceipts
{
    public function __construct(private ConnectionInterface $connection) {}

    // Conflict target is the merchant/key pair: a bare ON CONFLICT would also swallow the
    // payment_id unique and report a permanent collision as retryable contention (ADR 0003).
    #[Override]
    public function reserve(PaymentInitiationContext $context): bool
    {
        $now = CarbonImmutable::now();

        $returned = $this->connection->table('payment_idempotency_receipts')
            ->insertOrIgnoreReturning(
                [
                    'id' => (string) Str::uuid7(),
                    'merchant_id' => $context->merchantId,
                    'idempotency_key_hash' => $context->idempotencyKeyHash,
                    'request_fingerprint' => $context->requestFingerprint,
                    'payment_id' => $context->paymentId,
                    'response_status' => $context->responseStatus,
                    'response_body' => $context->responseBody,
                    'response_location' => $context->responseLocation,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['id'],
                ['merchant_id', 'idempotency_key_hash'],
            );

        return $returned->count() === 1;
    }

    #[Override]
    public function findWinner(int $merchantId, string $idempotencyKeyHash): ?StoredIdempotencyReceipt
    {
        /** @var object{payment_id: string, request_fingerprint: string, response_status: int|string, response_body: string, response_location: string}|null $row */
        $row = $this->connection->table('payment_idempotency_receipts')
            ->where('merchant_id', $merchantId)
            ->where('idempotency_key_hash', $idempotencyKeyHash)
            ->first();

        if ($row === null) {
            return null;
        }

        return new StoredIdempotencyReceipt(
            paymentId: $row->payment_id,
            requestFingerprint: $row->request_fingerprint,
            responseStatus: (int) $row->response_status,
            responseBody: $row->response_body,
            responseLocation: $row->response_location,
        );
    }
}
