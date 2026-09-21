<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use Modules\Merchant\Infrastructure\Persistence\AtomicRefreshTokenRepository;
use Modules\Merchant\Tests\Support\Authorization;

/** @return array{0: Client, 1: array<string, mixed>} */
function grantedPair(): array
{
    $client = Authorization::bffClient();
    $tokens = Authorization::grant($client);

    $tokens->assertOk();

    /** @var array<string, mixed> $body */
    $body = $tokens->json();

    return [$client, $body];
}

function accessTokenId(string $accessToken): string
{
    return (string) Authorization::claims($accessToken)['jti'];
}

function isRevoked(string $table, string $column, string $value): bool
{
    return (bool) DB::table($table)->where($column, $value)->value('revoked');
}

it('returns a new access and refresh pair for a valid refresh token', function (): void {
    [$client, $granted] = grantedPair();

    $refreshed = Authorization::refresh($client, (string) $granted['refresh_token']);

    $refreshed->assertOk();

    expect($refreshed->json('token_type'))->toBe('Bearer')
        ->and($refreshed->json('access_token'))->toBeString()
        ->and($refreshed->json('access_token'))->not->toBe($granted['access_token'])
        ->and($refreshed->json('refresh_token'))->toBeString()
        ->and($refreshed->json('refresh_token'))->not->toBe($granted['refresh_token']);

    $claims = Authorization::claims((string) $refreshed->json('access_token'));

    expect((int) $claims['exp'] - (int) $claims['iat'])->toBe(15 * 60);
});

it('revokes the presented refresh token and its access token', function (): void {
    [$client, $granted] = grantedPair();

    $oldAccessTokenId = accessTokenId((string) $granted['access_token']);
    $oldRefreshTokenId = (string) DB::table('oauth_refresh_tokens')
        ->where('access_token_id', $oldAccessTokenId)
        ->value('id');

    expect(isRevoked('oauth_access_tokens', 'id', $oldAccessTokenId))->toBeFalse()
        ->and(isRevoked('oauth_refresh_tokens', 'id', $oldRefreshTokenId))->toBeFalse();

    Authorization::refresh($client, (string) $granted['refresh_token'])->assertOk();

    expect(isRevoked('oauth_access_tokens', 'id', $oldAccessTokenId))->toBeTrue()
        ->and(isRevoked('oauth_refresh_tokens', 'id', $oldRefreshTokenId))->toBeTrue();
});

it('authenticates the resource api with the rotated access token', function (): void {
    [$client, $granted] = grantedPair();

    $refreshed = Authorization::refresh($client, (string) $granted['refresh_token']);
    $paymentId = (string) Str::uuid7();

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$refreshed->json('access_token'))
        ->getJson('/api/payments/'.$paymentId)
        ->assertStatus(404);

    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$granted['access_token'])
        ->getJson('/api/payments/'.$paymentId)
        ->assertStatus(401);
});

it('refuses to replay a refresh token that has already rotated', function (): void {
    [$client, $granted] = grantedPair();

    Authorization::refresh($client, (string) $granted['refresh_token'])->assertOk();

    $replay = Authorization::refresh($client, (string) $granted['refresh_token']);

    expect($replay->json('error'))->toBe('invalid_grant')
        ->and($replay->getStatusCode())->toBeGreaterThanOrEqual(400);
});

it('issues one successor pair when the same refresh token is consumed twice', function (): void {
    [$client, $granted] = grantedPair();

    $issuedBefore = DB::table('oauth_refresh_tokens')->count();

    $winner = Authorization::refresh($client, (string) $granted['refresh_token']);
    $loser = Authorization::refresh($client, (string) $granted['refresh_token']);

    $winner->assertOk();

    expect($loser->json('error'))->toBe('invalid_grant')
        ->and(DB::table('oauth_refresh_tokens')->count())->toBe($issuedBefore + 1)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(1)
        ->and(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(1);
});

it('refuses the loser when a competing refresh lands between validation and rotation', function (): void {
    [$client, $granted] = grantedPair();

    $refreshTokenId = (string) DB::table('oauth_refresh_tokens')
        ->where('access_token_id', accessTokenId((string) $granted['access_token']))
        ->value('id');

    $repository = resolve(RefreshTokenRepository::class);

    // The interleaving the compare-and-set exists for: this attempt has validated the token, and a
    // competing refresh completes before it reaches its own revocation.
    expect($repository->isRefreshTokenRevoked($refreshTokenId))->toBeFalse();

    Authorization::refresh($client, (string) $granted['refresh_token'])->assertOk();

    expect(fn () => $repository->revokeRefreshToken($refreshTokenId))
        ->toThrow(OAuthServerException::class);

    expect(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(1);
});

it('binds the atomic repository into the grants Passport builds', function (): void {
    expect(resolve(RefreshTokenRepository::class))
        ->toBeInstanceOf(AtomicRefreshTokenRepository::class);
});
