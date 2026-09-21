<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence;

use Laravel\Passport\Passport;
use RuntimeException;

final readonly class MerchantTokenRevocation
{
    public function revoke(string $accessTokenId): void
    {
        $tokens = Passport::token()->newQuery();
        $refreshTokens = Passport::refreshToken()->newQuery();
        $connection = $tokens->getConnection();

        // Passport resolves each model's connection through config('passport.connection'), and two
        // rows on two connections could not move together.
        if ($refreshTokens->getConnection() !== $connection) {
            throw new RuntimeException('Passport access tokens and refresh tokens must share one connection.');
        }

        // One transaction: a bearer that stops working while its refresh token still rotates would
        // hand the caller a live credential back.
        $connection->transaction(function () use ($tokens, $refreshTokens, $accessTokenId): void {
            $tokens->whereKey($accessTokenId)->update(['revoked' => true]);

            $refreshTokens->where('access_token_id', $accessTokenId)->update(['revoked' => true]);
        });
    }
}
