<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

final readonly class ReceiptCandidate
{
    /** @param  string|null  $intentId  null only for receipts written before intents existed */
    public function __construct(
        public ?string $intentId,
        public StoredIdempotencyReceipt $receipt,
    ) {}
}
