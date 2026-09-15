<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Modules\Merchant\Application\Identity\MerchantContext;
use Override;

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
