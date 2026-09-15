<?php

declare(strict_types=1);

namespace Modules\Merchant\Application\Identity;

interface MerchantContext
{
    /** @throws NoAuthenticatedMerchantUser */
    public function currentMerchantId(): int;
}
