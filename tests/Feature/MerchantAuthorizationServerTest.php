<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Modules\Merchant\Tests\Support\Authorization;

it('registers exactly the authorization and token endpoints', function (): void {
    $oauth = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'oauth'))
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($oauth)->toEqualCanonicalizing([
        'GET|HEAD oauth/authorize',
        'POST oauth/token',
    ]);
});

it('keeps the token endpoint outside sessions and CSRF', function (): void {
    $middleware = Route::getRoutes()->getByName('oauth.token')?->gatherMiddleware() ?? [];

    expect($middleware)->toBe(['throttle:oauth-token']);

    expect(Route::getRoutes()->getByName('oauth.authorize')?->gatherMiddleware() ?? [])
        ->toContain('web');
});

it('sends a guest to login and resumes the intended authorization afterwards', function (): void {
    $client = Authorization::bffClient();
    $user = Authorization::merchant();
    $authorize = Authorization::authorizeUrl($client, Authorization::pkce()['challenge']);

    $this->get($authorize)->assertRedirect(route('login'));

    $login = Authorization::signIn($user);
    $login->assertStatus(302);

    parse_str((string) parse_url((string) $login->headers->get('Location'), PHP_URL_QUERY), $resumed);
    parse_str((string) parse_url($authorize, PHP_URL_QUERY), $requested);

    expect((string) $login->headers->get('Location'))->toStartWith(url('/oauth/authorize').'?')
        ->and($resumed)->toEqualCanonicalizing($requested)
        ->and(auth()->guard('merchant_web')->id())->toBe($user->getKey());
});

it('issues a code to the configured BFF client without a consent screen', function (): void {
    $client = Authorization::bffClient();
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());

    $code = Authorization::code($this->get(Authorization::authorizeUrl($client, $pkce['challenge'])));

    expect($code)->not->toBe('');
});

it('exchanges a code bound to its S256 verifier for the ADR 0006 token lifetimes', function (): void {
    $client = Authorization::bffClient();
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());
    $code = Authorization::code($this->get(Authorization::authorizeUrl($client, $pkce['challenge'])));

    $tokens = Authorization::exchange($client, $code, $pkce['verifier']);
    $tokens->assertOk();

    $claims = Authorization::claims((string) $tokens->json('access_token'));

    // exp - iat is exact; expires_in is derived against a second clock read and may lose a second.
    expect($tokens->json('token_type'))->toBe('Bearer')
        ->and((int) $claims['exp'] - (int) $claims['iat'])->toBe(15 * 60)
        ->and($tokens->json('expires_in'))->toBeGreaterThan(15 * 60 - 5)
        ->and($tokens->json('expires_in'))->toBeLessThanOrEqual(15 * 60)
        ->and($tokens->json('refresh_token'))->toBeString();

    $refreshExpiry = Date::parse((string) DB::table('oauth_refresh_tokens')->value('expires_at'));

    expect($refreshExpiry->between(now()->addDays(14)->subMinute(), now()->addDays(14)))->toBeTrue();
});

it('sets no session cookie on the token endpoint', function (): void {
    $client = Authorization::bffClient();
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());
    $code = Authorization::code($this->get(Authorization::authorizeUrl($client, $pkce['challenge'])));

    $this->flushSession();
    $tokens = Authorization::exchange($client, $code, $pkce['verifier']);

    $tokens->assertOk();

    expect($tokens->headers->getCookies())->toBe([]);
});

it('refuses an authorization request without a usable code challenge', function (array $overrides): void {
    $client = Authorization::bffClient();

    Authorization::signIn(Authorization::merchant());

    $response = $this->get(Authorization::authorizeUrl($client, Authorization::pkce()['challenge'], $overrides));

    $response->assertStatus(400);

    expect($response->json('error'))->toBe('invalid_request')
        ->and($response->headers->get('Location'))->toBeNull();
})->with([
    'a plain challenge method' => [['code_challenge_method' => 'plain']],
    'no challenge method' => [['code_challenge_method' => '']],
    'no challenge' => [['code_challenge' => '']],
]);

it('refuses an authorization request whose redirect uri is not the exact registered one', function (string $redirectUri): void {
    $client = Authorization::bffClient();

    Authorization::signIn(Authorization::merchant());

    $response = $this->get(Authorization::authorizeUrl($client, Authorization::pkce()['challenge'], ['redirect_uri' => $redirectUri]));

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and((string) $response->headers->get('Location'))->not->toStartWith($redirectUri);
})->with([
    'a trailing slash' => Authorization::REDIRECT_URI.'/',
    'another path' => 'http://localhost:3000/auth/callback2',
    'another port' => 'http://localhost:3001/auth/callback',
]);

it('refuses a token exchange presenting the wrong verifier', function (): void {
    $client = Authorization::bffClient();
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());
    $code = Authorization::code($this->get(Authorization::authorizeUrl($client, $pkce['challenge'])));

    $response = Authorization::exchange($client, $code, Authorization::pkce()['verifier']);

    $response->assertStatus(400);

    expect($response->json('error'))->toBe('invalid_grant');
});

it('refuses to replay an authorization code', function (): void {
    $client = Authorization::bffClient();
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());
    $code = Authorization::code($this->get(Authorization::authorizeUrl($client, $pkce['challenge'])));

    Authorization::exchange($client, $code, $pkce['verifier'])->assertOk();

    $replay = Authorization::exchange($client, $code, $pkce['verifier']);

    $replay->assertStatus(400);

    expect($replay->json('error'))->toBe('invalid_grant');
});

it('never skips consent for a client that is not the configured one', function (): void {
    Authorization::bffClient();
    $other = Authorization::client('another-client');
    $pkce = Authorization::pkce();

    Authorization::signIn(Authorization::merchant());

    $response = $this->get(Authorization::authorizeUrl($other, $pkce['challenge']));

    $response->assertStatus(403);

    expect($response->headers->get('Location'))->toBeNull()
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('never treats another client\'s existing token as granted consent', function (): void {
    Authorization::bffClient();
    $other = Authorization::client('another-client');
    $user = Authorization::merchant();

    Passport::token()->newQuery()->forceCreate([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => $other->getKey(),
        'name' => 'previously granted',
        'scopes' => [],
        'revoked' => false,
        'expires_at' => now()->addHour(),
    ]);

    Authorization::signIn($user);

    $response = $this->get(Authorization::authorizeUrl($other, Authorization::pkce()['challenge']));

    $response->assertStatus(403);

    expect($response->headers->get('Location'))->toBeNull()
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('spends the merchant authorization session on one authorization', function (): void {
    $client = Authorization::bffClient();
    $user = Authorization::merchant();

    Authorization::signIn($user);

    Authorization::code($this->get(Authorization::authorizeUrl($client, Authorization::pkce()['challenge'])));

    expect(auth()->guard('merchant_web')->check())->toBeFalse();

    $this->get(Authorization::authorizeUrl($client, Authorization::pkce()['challenge']))->assertRedirect(route('login'));
});

it('spends the merchant authorization session on a refused attempt too', function (array $overrides, bool $otherClient): void {
    $bff = Authorization::bffClient();
    $user = Authorization::merchant();
    $client = $otherClient ? Authorization::client('another-client') : $bff;

    Authorization::signIn($user);

    expect(auth()->guard('merchant_web')->id())->toBe($user->getKey());

    $refused = $this->get(Authorization::authorizeUrl($client, Authorization::pkce()['challenge'], $overrides));

    expect($refused->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and(auth()->guard('merchant_web')->check())->toBeFalse()
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);

    $this->get(Authorization::authorizeUrl($bff, Authorization::pkce()['challenge']))->assertRedirect(route('login'));
})->with([
    'a plain code challenge' => [['code_challenge_method' => 'plain'], false],
    'no code challenge' => [['code_challenge' => ''], false],
    'a client that may not skip consent' => [[], true],
]);

it('throttles merchant login to five attempts a minute for one email and address', function (): void {
    $user = Authorization::merchant();

    foreach (range(1, 5) as $ignored) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(302);
    }

    $this->post('/login', ['email' => strtoupper($user->email), 'password' => Authorization::PASSWORD])
        ->assertStatus(429);

    expect(auth()->guard('merchant_web')->check())->toBeFalse();

    $this->post('/login', ['email' => 'someone.else@northwind.test', 'password' => 'wrong'])
        ->assertStatus(302);
});

it('answers an unauthenticated api request with the 401 problem document and never a login redirect', function (string $accept): void {
    $response = $this->call('GET', '/api/payments/'.Str::uuid7(), server: ['HTTP_ACCEPT' => $accept]);

    $response->assertStatus(401);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->headers->get('Location'))->toBeNull()
        ->and($response->json('type'))->toBeString();
})->with([
    'a browser Accept header' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'no Accept header at all' => '',
    'an explicit json Accept header' => 'application/json',
]);
