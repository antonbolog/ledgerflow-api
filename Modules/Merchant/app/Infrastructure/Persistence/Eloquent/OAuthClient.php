<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence\Eloquent;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Override;

final class OAuthClient extends Client
{
    // No consent screen exists, so the skip is keyed to one client id. firstParty() alone would
    // cover every ownerless client ever registered.
    #[Override]
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        $configured = config('passport.bff_client_id');

        return is_string($configured)
            && $configured !== ''
            && $configured === $this->getKey()
            && $this->firstParty()
            && $this->confidential();
    }
}
