<?php

declare(strict_types=1);

namespace Modules\Merchant\Application\Identity;

use RuntimeException;

final class NoAuthenticatedMerchantUser extends RuntimeException
{
    public static function forGuard(string $guard): self
    {
        return new self("No merchant user is authenticated on the [{$guard}] guard.");
    }
}
