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

/**
 * PostgreSQL arbitration over (merchant_id, idempotency_key_hash).
 *
 * ConnectionInterface is a container alias of db.connection, which resolves to
 * $app['db']->connection() — the default connection, cached per name by
 * DatabaseManager. The retry runner, this adapter, Spatie's EloquentStoredEvent and
 * the outbox projector therefore all hold the same Connection object, which is what
 * makes "one transaction, one connection" true rather than hoped for.
 */
final readonly class DatabaseIdempotencyReceipts implements IdempotencyReceipts
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * TARGETED CONFLICT CLAUSE, NOT A BARE `on conflict do nothing`.
     *
     * payment_idempotency_receipts carries TWO unique constraints: the merchant/key
     * pair this method arbitrates on, and a GLOBAL unique on payment_id. A bare
     * insertOrIgnore() compiles to `on conflict do nothing` with no target
     * (PostgresGrammar::compileInsertOrIgnore), which swallows BOTH — so a
     * payment_id collision would affect zero rows, findWinner() would find no winner
     * for this merchant/key, and the caller would answer 503 idempotency_contention:
     * a retryable answer to a permanent condition, which a well-behaved client
     * retries forever.
     *
     * insertOrIgnoreReturning() names the constraint, so a payment_id collision
     * raises a real 23505 instead. The retry allowlist refuses it and it surfaces as
     * a 500.
     *
     * H-1 measured the concurrent case against PostgreSQL 18.4: this statement WAITS
     * on the other transaction's id, so an empty return implies a committed,
     * readable winner rather than an in-flight one.
     *
     * THE ROW IS COMPLETE AT INSERT. Status, body bytes and Location all come from
     * the immutable context, which is why a replay can be answered without
     * re-deriving anything — and why the bytes a retry receives are the same bytes,
     * not an equivalent document.
     */
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

        // RETURNING gives back a row only when this statement actually inserted one.
        // Zero rows means the merchant/key pair was already taken — and H-1 measured
        // that the statement waits for a concurrent inserter to finish, so the winner
        // is committed and readable by the time we get here.
        return $returned->count() === 1;
    }

    #[Override]
    public function findWinner(int $merchantId, string $idempotencyKeyHash): ?StoredIdempotencyReceipt
    {
        // The shape is the schema's, not a hope: every column named here is NOT NULL
        // on payment_idempotency_receipts, and the query builder returns an untyped
        // stdClass that level 10 sees as mixed. response_status stays int|string
        // because PDO's pgsql driver is free to hand back a smallint either way.
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
