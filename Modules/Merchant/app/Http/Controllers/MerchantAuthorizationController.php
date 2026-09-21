<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Override;

final class MerchantAuthorizationController extends AuthorizationController
{
    // Passport reuses a prior grant as consent. No consent screen exists here, so reuse would
    // extend the configured client's consent skip to any client that ever held a token.
    #[Override]
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
