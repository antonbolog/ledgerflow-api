<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\ReceiptCandidate;
use Modules\Payments\Application\Initiation\ReservationRaced;
use Modules\Payments\Application\Initiation\StoredIdempotencyReceipt;
use Override;

final readonly class DatabaseIdempotencyReceipts implements IdempotencyReceipts
{
    public function __construct(private ConnectionInterface $connection) {}

    #[Override]
    public function findArbitrationCandidates(int $merchantId, string $intentId, string $idempotencyKeyHash): array
    {
        /** @var list<object{intent_id: string|null, payment_id: string, request_fingerprint: string, response_status: int|string, response_body: string, response_location: string}> $rows */
        $rows = $this->connection->table('payment_idempotency_receipts')
            ->where('merchant_id', $merchantId)
            ->where(fn (Builder $query): Builder => $query->where('intent_id', $intentId)->orWhere('idempotency_key_hash', $idempotencyKeyHash))
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(
            fn (object $row): ReceiptCandidate => new ReceiptCandidate($row->intent_id, self::receipt($row)),
            $rows,
        );
    }

    // The intent pair is the only conflict target, so any 23505 here comes from another unique key by construction;
    // a bare ON CONFLICT would also swallow the key and payment_id uniques (ADR 0003).
    #[Override]
    public function reserveIntent(PaymentInitiationContext $context): bool
    {
        try {
            $returned = $this->connection->table('payment_idempotency_receipts')
                ->insertOrIgnoreReturning(
                    $this->row($context),
                    ['id'],
                    ['merchant_id', 'intent_id'],
                );
        } catch (UniqueConstraintViolationException $exception) {
            throw ReservationRaced::onAnotherUniqueKey($exception);
        }

        return $returned->count() === 1;
    }

    /** @return array<string, mixed> */
    private function row(PaymentInitiationContext $context): array
    {
        // statement_timestamp(), not now(): PostgreSQL owns the clock, and now() would freeze at transaction start.
        $now = $this->connection->raw('statement_timestamp()');

        return [
            'id' => (string) Str::uuid7(),
            'merchant_id' => $context->merchantId,
            'idempotency_key_hash' => $context->idempotencyKeyHash,
            'request_fingerprint' => $context->requestFingerprint,
            'payment_id' => $context->paymentId,
            'response_status' => $context->responseStatus,
            'response_body' => $context->responseBody,
            'response_location' => $context->responseLocation,
            'intent_id' => $context->intentId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @param object{payment_id: string, request_fingerprint: string, response_status: int|string, response_body: string, response_location: string} $row */
    private static function receipt(object $row): StoredIdempotencyReceipt
    {
        return new StoredIdempotencyReceipt(
            paymentId: $row->payment_id,
            requestFingerprint: $row->request_fingerprint,
            responseStatus: (int) $row->response_status,
            responseBody: $row->response_body,
            responseLocation: $row->response_location,
        );
    }
}
