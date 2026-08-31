<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Modules\Merchant\Application\Identity\MerchantContext;
use Override;

/**
 * A MerchantContext that answers a fixed id and counts how often it was asked.
 *
 * WHY COUNTING MATTERS HERE: AuthenticatedMerchantContext resolves the real Sanctum
 * guard on every call and memoises nothing, so a controller that asked twice would
 * do the identity work twice and no assertion about the response would notice.
 */
final class CountingMerchantContext implements MerchantContext
{
    public int $calls = 0;

    public function __construct(private readonly int $merchantId) {}

    #[Override]
    public function currentMerchantId(): int
    {
        $this->calls++;

        return $this->merchantId;
    }
}
