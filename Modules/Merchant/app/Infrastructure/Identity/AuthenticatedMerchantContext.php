<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Identity;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Application\Identity\NoAuthenticatedMerchantUser;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

/**
 * Resolves the request's merchant from the authenticated Sanctum user.
 */
final readonly class AuthenticatedMerchantContext implements MerchantContext
{
    public function __construct(
        private AuthFactory $auth,
        private string $guard,
    ) {}

    public function currentMerchantId(): int
    {
        // Resolved on every call, never cached on this object. The guard behind
        // AuthFactory holds the per-request identity, and it is the framework
        // that resets it between requests and queued jobs; caching here would
        // quietly outlive that reset.
        $user = $this->auth->guard($this->guard)->user();

        // instanceof rather than a null check: a differently configured guard
        // could resolve some other Authenticatable, and merchant ownership is
        // not a property this code may assume of an arbitrary user type.
        if (! $user instanceof User) {
            throw NoAuthenticatedMerchantUser::forGuard($this->guard);
        }

        return $user->merchant_id;
    }
}
