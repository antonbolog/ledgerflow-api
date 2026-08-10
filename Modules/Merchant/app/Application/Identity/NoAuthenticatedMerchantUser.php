<?php

declare(strict_types=1);

namespace Modules\Merchant\Application\Identity;

use RuntimeException;

/**
 * Thrown rather than returning null: every caller of currentMerchantId() needs
 * a merchant, so a null return would only push an unhandled case one frame out.
 */
final class NoAuthenticatedMerchantUser extends RuntimeException
{
    public static function forGuard(string $guard): self
    {
        return new self("No merchant user is authenticated on the [{$guard}] guard.");
    }
}
