<?php

declare(strict_types=1);

namespace Modules\Merchant\Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

// Drives the real authorization server over HTTP, never Passport::actingAs, which bypasses the
// guard, the grant and the token tables.
final class Authorization
{
    public const string REDIRECT_URI = 'http://localhost:3000/auth/callback';

    public const string PASSWORD = 'correct-horse-battery';

    public const string STATE = 'opaque-state';

    public static function client(string $name, string $redirectUri = self::REDIRECT_URI): Client
    {
        return resolve(ClientRepository::class)->createAuthorizationCodeGrantClient($name, [$redirectUri]);
    }

    public static function bffClient(): Client
    {
        $client = self::client('ledgerflow-bff');

        config(['passport.bff_client_id' => (string) $client->getKey()]);

        return $client;
    }

    public static function merchant(): User
    {
        return User::factory()->for(Merchant::factory())->create(['password' => self::PASSWORD]);
    }

    /** @return array{verifier: string, challenge: string} */
    public static function pkce(): array
    {
        $verifier = Str::random(64);

        return [
            'verifier' => $verifier,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        ];
    }

    /** @param array<string, string> $overrides */
    public static function authorizeUrl(Client $client, string $challenge, array $overrides = []): string
    {
        return '/oauth/authorize?'.http_build_query([
            'client_id' => (string) $client->getKey(),
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => self::STATE,
            ...$overrides,
        ]);
    }

    public static function signIn(User $user): TestResponse
    {
        return test()->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    public static function code(TestResponse $response): string
    {
        $location = (string) $response->headers->get('Location');

        expect($location)->toStartWith(self::REDIRECT_URI.'?');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        expect($query['state'] ?? null)->toBe(self::STATE)
            ->and($query['code'] ?? null)->toBeString();

        return (string) $query['code'];
    }

    /** @param array<string, string> $overrides */
    public static function exchange(Client $client, string $code, string $verifier, array $overrides = []): TestResponse
    {
        return test()->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => (string) $client->getKey(),
            'client_secret' => (string) $client->plainSecret,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => $code,
            'code_verifier' => $verifier,
            ...$overrides,
        ]);
    }

    /** @param array<string, string> $overrides */
    public static function refresh(Client $client, string $refreshToken, array $overrides = []): TestResponse
    {
        return test()->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => (string) $client->getKey(),
            'client_secret' => (string) $client->plainSecret,
            'refresh_token' => $refreshToken,
            ...$overrides,
        ]);
    }

    /** Signs a merchant in and completes one authorization code exchange. */
    public static function grant(Client $client, ?User $user = null): TestResponse
    {
        $pkce = self::pkce();

        self::signIn($user ?? self::merchant());

        $code = self::code(test()->get(self::authorizeUrl($client, $pkce['challenge'])));

        return self::exchange($client, $code, $pkce['verifier']);
    }

    /** @return array<string, mixed> */
    public static function claims(string $jwt): array
    {
        /** @var array<string, mixed> $claims */
        $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);

        return $claims;
    }
}
