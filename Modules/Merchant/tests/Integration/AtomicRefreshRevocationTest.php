<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use League\OAuth2\Server\Exception\OAuthServerException;

const REFRESH_CONTENDER = 'pgsql_merchant_refresh_contender';

function refreshTokenRow(): string
{
    $id = Str::random(80);

    DB::table('oauth_refresh_tokens')->insert([
        'id' => $id,
        'access_token_id' => Str::random(80),
        'revoked' => false,
        'expires_at' => now()->addDays(14),
    ]);

    return $id;
}

beforeEach(function (): void {
    if (! Schema::hasTable('oauth_refresh_tokens')) {
        Artisan::call('migrate', ['--force' => true]);
    }

    config(['database.connections.'.REFRESH_CONTENDER => config('database.connections.pgsql')]);

    DB::purge(REFRESH_CONTENDER);
    DB::table('oauth_refresh_tokens')->delete();
});

afterEach(function (): void {
    try {
        $contender = DB::connection(REFRESH_CONTENDER);

        while ($contender->transactionLevel() > 0) {
            $contender->rollBack();
        }
    } catch (Throwable) {
        // Already released; never mask the test's own failure.
    }

    DB::purge(REFRESH_CONTENDER);
    DB::statement('set lock_timeout = default');
    DB::table('oauth_refresh_tokens')->delete();
});

it('moves a live refresh token exactly once', function (): void {
    $id = refreshTokenRow();
    $repository = resolve(RefreshTokenRepository::class);

    $repository->revokeRefreshToken($id);

    expect(DB::table('oauth_refresh_tokens')->where('id', $id)->value('revoked'))->toBeTruthy();

    expect(fn () => $repository->revokeRefreshToken($id))->toThrow(OAuthServerException::class);
});

it('refuses a token another session revoked while this one waited for its row', function (): void {
    $id = refreshTokenRow();
    $repository = resolve(RefreshTokenRepository::class);

    $contender = DB::connection(REFRESH_CONTENDER);
    $contender->beginTransaction();

    expect($contender->update(
        'update oauth_refresh_tokens set revoked = true where id = ? and revoked = false',
        [$id],
    ))->toBe(1);

    // Bounded rather than slept on: while the contender holds the row, this statement can only
    // wait, which is what proves it contends for the row instead of passing over it.
    DB::statement("set lock_timeout = '250ms'");

    expect(fn () => $repository->revokeRefreshToken($id))->toThrow(QueryException::class);

    DB::statement('set lock_timeout = default');

    $contender->commit();

    expect(fn () => $repository->revokeRefreshToken($id))->toThrow(OAuthServerException::class)
        ->and(DB::table('oauth_refresh_tokens')->where('id', $id)->value('revoked'))->toBeTruthy();
});

it('lets the same statement through when no other session holds the row', function (): void {
    $id = refreshTokenRow();

    DB::statement("set lock_timeout = '250ms'");

    expect(fn () => resolve(RefreshTokenRepository::class)->revokeRefreshToken($id))->not->toThrow(QueryException::class);
});
