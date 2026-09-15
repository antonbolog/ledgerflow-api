<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Identity;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Application\Identity\NoAuthenticatedMerchantUser;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

final readonly class AuthenticatedMerchantContext implements MerchantContext
{
    public function __construct(
        private AuthFactory $auth,
        private string $guard,
    ) {}

    public function currentMerchantId(): int
    {
        // Never cache: the framework resets guard identity between requests and queued jobs.
        $user = $this->auth->guard($this->guard)->user();

        if (! $user instanceof User) {
            throw NoAuthenticatedMerchantUser::forGuard($this->guard);
        }

        return $user->merchant_id;
    }
}
