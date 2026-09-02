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
use Override;

/**
 * The owned payment read, as two indexed point lookups inside one short
 * transaction.
 *
 * ConnectionInterface is a container alias of db.connection, so this adapter holds
 * the SAME default Connection object as the write path, the relay and the
 * consumer. That identity is what makes "one transaction, one connection" a fact
 * rather than a hope - and it is why T11 can prepend a connection-affine gate
 * acquisition to this same transaction without moving either read.
 *
 * WHAT THE TRANSACTION IS FOR, AND WHAT IT IS NOT. It gives the two lookups one
 * owned connection and one explicit boundary, which is the seam T11 prepends its
 * connection-affine gate acquisition to. It does NOT give them a single snapshot:
 * under READ COMMITTED each statement takes its own, so a projection that commits
 * between the lookup and the fallback yields a pending answer for a payment that
 * is projected a moment later. That is correct eventual-consistency behaviour and
 * is exactly what the 202 contract's Retry-After tells the client to do about it.
 * Nothing here changes isolation to avoid it.
 *
 * IT READS TWO TABLES AND WRITES NOTHING. No aggregate rehydration, no
 * stored_events, no payment_outbox, no projector receipt, no Redis. A read model
 * that had to consult the event store to answer a query would not be a read model.
 */
final readonly class DatabasePaymentQuery implements PaymentQuery
{
    public function __construct(private ConnectionInterface $connection) {}

    /**
     * MALFORMED IS REFUSED BEFORE PostgreSQL SEES IT, and that is a contract
     * decision rather than defensive habit. `where payment_id = 'nonsense'`
     * against a uuid column raises 22P02, which would surface as a 500 and would
     * make a syntactically invalid unknown id a DISTINGUISHABLE error class from a
     * valid unknown one. Both are simply unknown.
     *
     * STRICTER THAN PostgreSQL, DELIBERATELY. Str::isUuid accepts only the
     * canonical hyphenated form, while PostgreSQL's uuid input also takes braced
     * and unhyphenated spellings, so an owned payment addressed as `{0199...}`
     * answers 404 rather than 200. That is the intended trade: the canonical form
     * is the only one this API ever emits, and one accepted spelling per
     * identifier keeps the not-found answer indivisible.
     */
    #[Override]
    public function findOwned(int $merchantId, string $paymentId): ProjectedPayment|PendingPayment
    {
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
        $projected = $this->projected($merchantId, $paymentId);

        // SHORT-CIRCUIT, NOT A PREFERENCE BETWEEN TWO RESULTS. A projected payment
        // keeps its idempotency receipt forever, so both rows normally exist; asking
        // for the receipt as well would be a second statement whose answer could
        // never change this one.
        if ($projected !== null) {
            return $projected;
        }

        if ($this->hasOwnedReceipt($merchantId, $paymentId)) {
            return new PendingPayment($paymentId);
        }

        throw PaymentNotFound::forRequestedPayment();
    }

    /**
     * BOTH PREDICATES, ALWAYS. payment_id is the primary key, so the merchant
     * predicate adds no lookup cost - it is what turns "this payment" into "this
     * merchant's payment". Dropping it would return another merchant's row.
     *
     * The columns are named rather than selected with `*`: a column added to
     * payment_details later cannot leak into a response by arriving in a row
     * object nobody re-read.
     */
    private function projected(int $merchantId, string $paymentId): ?ProjectedPayment
    {
        // Every column named here is NOT NULL on payment_details. The query builder
        // hands back an untyped stdClass that level 10 sees as mixed, and PDO's
        // pgsql driver is free to return a bigint as either int or string.
        /** @var object{payment_id: string, amount_minor: int|string, currency: string, status: string, initiated_at: string}|null $row */
        $row = $this->connection->table('payment_details')
            ->where('payment_id', $paymentId)
            ->where('merchant_id', $merchantId)
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

    /**
     * `exists()`, not a row fetch. The receipt's business columns are the initiation
     * response's, not this endpoint's, and the only fact needed here is that the
     * merchant owns a committed payment with this id. Fetching the row would put
     * stored response bytes in reach of a serializer that must never see them.
     *
     * Both predicates are already covered by existing keys - payment_id by
     * payment_receipts_payment_id_unique, the pair by
     * payment_receipts_merchant_payment_index - so no index is added for this
     * path. WHICH ONE THE PLANNER PICKS IS NOT CLAIMED HERE: no EXPLAIN was run,
     * and the projection and query specification disclaims query-plan claims
     * outright.
     */
    private function hasOwnedReceipt(int $merchantId, string $paymentId): bool
    {
        return $this->connection->table('payment_idempotency_receipts')
            ->where('merchant_id', $merchantId)
            ->where('payment_id', $paymentId)
            ->exists();
    }

    /**
     * The instant PostgreSQL stored, in the one representation the specification
     * pins: UTC, ISO-8601, six fractional digits, +00:00.
     *
     * FORMATTED, NOT REINTERPRETED. The column is timestamp(6) with time zone and
     * initiated_at is derived from the event's occurred_at, so the value is already
     * exact; ->utc() only normalises how the offset is spelled. `u` is six digits
     * always, which is why a whole-second instant still renders .000000 rather than
     * changing shape between payments.
     */
    private static function canonicalInstant(string $stored): string
    {
        return CarbonImmutable::parse($stored)->utc()->format('Y-m-d\TH:i:s.uP');
    }
}
