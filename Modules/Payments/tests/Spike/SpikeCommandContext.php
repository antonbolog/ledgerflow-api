<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Support\Str;

/**
 * The immutable command context the spec requires to be built BEFORE the
 * transaction opens, and reused unchanged across every retry attempt.
 *
 * Every field here is stable by contract. A retry rebuilds the aggregate and the
 * event from this; it never rebuilds this.
 */
final readonly class SpikeCommandContext
{
    public function __construct(
        public string $paymentId,
        public string $eventId,
        public int $merchantId,
        public int $amountMinor,
        public string $currency,
        public string $occurredAt,
        public string $keyHash,
    ) {}

    /**
     * The key hash is unique per context and 64 characters wide, matching the
     * production column.
     *
     * It must be UNIQUE ACROSS CONTEXTS so a receipt left behind by an earlier
     * test cannot satisfy this one's reservation. A shared constant made every
     * context collide with every other, which turned a failed cleanup into a
     * silent pass rather than a failure.
     *
     * It must be STABLE WITHIN A CONTEXT because a retry reuses the same context
     * object across attempts, and the spec requires the fingerprint to be part of
     * the immutable command data. Readonly promotion gives that for free: the
     * value is computed once here and can never be recomputed per attempt.
     */
    public static function make(int $merchantId = 1): self
    {
        return new self(
            paymentId: Str::uuid()->toString(),
            eventId: Str::uuid()->toString(),
            merchantId: $merchantId,
            amountMinor: 1250,
            currency: 'USD',
            occurredAt: '2026-08-12 09:00:00',
            keyHash: hash('sha256', Str::uuid()->toString()),
        );
    }
}
