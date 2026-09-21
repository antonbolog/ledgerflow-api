<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Persistence;

use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use Override;

final class AtomicRefreshTokenRepository extends RefreshTokenRepository
{
    #[Override]
    public function revokeRefreshToken(string $tokenId): void
    {
        $revoked = Passport::refreshToken()->newQuery()
            ->whereKey($tokenId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        // The grant validates and then revokes, so two concurrent refreshes both pass validation.
        // Only the update that moved the row may go on to issue a successor pair.
        if ($revoked !== 1) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }
    }
}
