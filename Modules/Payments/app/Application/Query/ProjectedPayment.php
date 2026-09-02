<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

/**
 * The projected read row, reduced to exactly what a merchant may see.
 *
 * THE ALLOWLIST IS THE TYPE. payment_details also carries merchant_id,
 * source_event_id and aggregate_version; ownership and source-event identity are
 * internal, and aggregate version is projection sequencing metadata. A field that
 * is not a constructor parameter here cannot be serialized by accident later.
 *
 * $initiatedAt is a STRING, already canonical: UTC ISO-8601 with six fractional
 * digits and a +00:00 offset, as pinned in the projection and query
 * specification. The adapter formats it once, at the boundary where the database
 * value is read, so no framework date type reaches Application and no caller can
 * choose a different representation.
 */
final readonly class ProjectedPayment
{
    public function __construct(
        public string $paymentId,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public string $initiatedAt,
    ) {}
}
