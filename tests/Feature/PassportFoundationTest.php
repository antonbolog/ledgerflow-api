<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Guards\TokenGuard;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Modules\Payments\Tests\Support\Initiation;

const PASSPORT_TABLE_COLUMNS = [
    'oauth_auth_codes' => ['id', 'user_id', 'client_id', 'scopes', 'revoked', 'expires_at'],
    'oauth_access_tokens' => ['id', 'user_id', 'client_id', 'name', 'scopes', 'revoked', 'created_at', 'updated_at', 'expires_at'],
    'oauth_refresh_tokens' => ['id', 'access_token_id', 'revoked', 'expires_at'],
    'oauth_clients' => ['id', 'owner_type', 'owner_id', 'name', 'secret', 'provider', 'redirect_uris', 'grant_types', 'revoked', 'created_at', 'updated_at'],
    'oauth_device_codes' => ['id', 'user_id', 'client_id', 'user_code', 'scopes', 'revoked', 'user_approved_at', 'last_polled_at', 'expires_at'],
];

/** @return list<string> */
function dockerignorePatterns(): array
{
    $path = base_path('.dockerignore');

    expect($path)->toBeReadableFile();

    return array_values(array_filter(
        array_map(trim(...), file($path, FILE_IGNORE_NEW_LINES) ?: []),
        fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));
}

function preflightFromBrowserOrigin(): TestResponse
{
    return test()->call('OPTIONS', '/api/payments', server: [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type, idempotency-key',
    ]);
}

it('installs Passport while registering none of its own routes', function (): void {
    // routes/oauth.php registers the two endpoints this application owns; Passport's default set
    // also carries /oauth/token/refresh, which turns a merchant_web session into a cookie
    // credential the token guard accepts on /api/* (ADR 0006, D-8).
    $passportRoutes = collect(Route::getRoutes()->getRoutes())->filter(
        fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'passport.'),
    );

    expect(app()->getProviders(PassportServiceProvider::class))->not->toBeEmpty()
        ->and($passportRoutes->map(fn (RoutingRoute $route): string => $route->uri())->values()->all())->toBe([])
        ->and(Passport::$registersRoutes)->toBeFalse();
});

it('disables the device authorization grant', function (): void {
    expect(Passport::$deviceCodeGrantEnabled)->toBeFalse();
});

it('declares exactly the api default guard and the dedicated merchant session guard', function (): void {
    expect(config('auth.defaults.guard'))->toBe('api')
        ->and(config('auth.guards'))->toBe([
            'api' => ['driver' => 'passport', 'provider' => 'users'],
            'merchant_web' => ['driver' => 'session', 'provider' => 'users'],
        ])
        ->and(config('passport.guard'))->toBe('merchant_web')
        ->and(Auth::guard('merchant_web'))->toBeInstanceOf(SessionGuard::class)
        ->and(Auth::guard())->toBeInstanceOf(TokenGuard::class);
});

it('issues access, refresh and personal access tokens with the ADR 0006 lifetimes', function (): void {
    expect(CarbonInterval::instance(Passport::tokensExpireIn())->totalSeconds)->toEqual(15 * 60)
        ->and(CarbonInterval::instance(Passport::refreshTokensExpireIn())->totalSeconds)->toEqual(14 * 24 * 3600)
        ->and(CarbonInterval::instance(Passport::personalAccessTokensExpireIn())->totalSeconds)->toEqual(3600);
});

it('migrates the Passport schema and retires the Sanctum token table', function (): void {
    foreach (PASSPORT_TABLE_COLUMNS as $table => $columns) {
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
    }

    $clientId = collect(Schema::getColumns('oauth_clients'))->firstWhere('name', 'id');

    expect($clientId['type_name'] ?? null)->toBe('uuid')
        ->and(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

it('drops the Sanctum token table going forward and restores only its empty schema on rollback', function (): void {
    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_09_17_170000_drop_personal_access_tokens_table.php');

    $migration->down();

    expect(Schema::getColumnListing('personal_access_tokens'))->toEqualCanonicalizing([
        'id', 'tokenable_type', 'tokenable_id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at',
    ])
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);

    $migration->up();

    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

it('answers a browser preflight with no CORS grant', function (): void {
    $response = preflightFromBrowserOrigin();

    foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Allow-Credentials'] as $header) {
        expect($response->headers->has($header))->toBeFalse();
    }
});

it('would detect a CORS grant if a browser-facing path were configured', function (): void {
    config([
        'cors.paths' => ['api/*'],
        'cors.allowed_methods' => ['*'],
        'cors.allowed_origins' => ['*'],
        'cors.allowed_headers' => ['*'],
    ]);

    expect(preflightFromBrowserOrigin()->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

it('adds no CORS header to an actual cross-origin api request', function (): void {
    $response = $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/payments', headers: Initiation::headers());

    $response->assertUnauthorized();

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});

it('keeps Passport key files out of the Docker build context', function (): void {
    $patterns = dockerignorePatterns();

    expect($patterns)->toContain('storage/*.key');

    foreach ($patterns as $pattern) {
        expect($pattern)->not->toStartWith('!storage');
    }
});
