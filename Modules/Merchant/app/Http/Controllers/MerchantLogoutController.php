<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\OAuthenticatable;
use Modules\Merchant\Infrastructure\Persistence\MerchantTokenRevocation;
use RuntimeException;

final readonly class MerchantLogoutController
{
    public function __construct(private MerchantTokenRevocation $revocation) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $token = $user instanceof OAuthenticatable ? $user->token() : null;

        if (! $token instanceof AccessToken) {
            throw new RuntimeException('The api guard authenticated a request carrying no access token.');
        }

        $this->revocation->revoke((string) $token->oauth_access_token_id);

        // No body: the response must carry neither token back to the caller.
        return response()->noContent();
    }
}
