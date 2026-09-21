<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Modules\Merchant\Infrastructure\Persistence\MerchantTokenRevocation;
use Modules\Merchant\Tests\Support\Authorization;

/** @return array{0: Client, 1: array<string, mixed>} */
function loggedInMerchant(?Client $client = null): array
{
    $client ??= Authorization::bffClient();
    $tokens = Authorization::grant($client);

    $tokens->assertOk();

    /** @var array<string, mixed> $body */
    $body = $tokens->json();

    return [$client, $body];
}

function callLogout(string $accessToken): TestResponse
{
    Auth::forgetGuards();

    return test()->withHeader('Authorization', 'Bearer '.$accessToken)->postJson('/api/auth/logout');
}

function callPayments(?string $accessToken): TestResponse
{
    Auth::forgetGuards();

    $headers = $accessToken === null ? [] : ['Authorization' => 'Bearer '.$accessToken];

    return test()->withHeaders($headers)->getJson('/api/payments/019bd0f6-3a8e-7c21-9d5a-2f4b6c8e0a13');
}

it('answers a logout with 204 and no body', function (): void {
    [, $granted] = loggedInMerchant();

    $response = callLogout((string) $granted['access_token']);

    $response->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and($response->headers->get('Content-Type'))->toBeNull();
});

it('revokes the presented access token and its refresh token together', function (): void {
    [$client, $granted] = loggedInMerchant();

    $accessTokenId = (string) Authorization::claims((string) $granted['access_token'])['jti'];

    callLogout((string) $granted['access_token'])->assertNoContent();

    expect(DB::table('oauth_access_tokens')->where('id', $accessTokenId)->value('revoked'))->toBeTruthy()
        ->and(DB::table('oauth_refresh_tokens')->where('access_token_id', $accessTokenId)->value('revoked'))->toBeTruthy();

    $replay = Authorization::refresh($client, (string) $granted['refresh_token']);

    expect($replay->json('error'))->toBe('invalid_grant');
});

it('answers the revoked access token with the canonical 401 problem document', function (): void {
    [, $granted] = loggedInMerchant();

    $expected = callPayments(null);
    $expected->assertStatus(401);

    callLogout((string) $granted['access_token'])->assertNoContent();

    $refused = callPayments((string) $granted['access_token']);

    $refused->assertStatus(401);

    expect($refused->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($refused->getContent())->toBe($expected->getContent());
});

it('answers a second logout with the same access token as any other unauthenticated call', function (): void {
    [, $granted] = loggedInMerchant();

    callLogout((string) $granted['access_token'])->assertNoContent();

    $second = callLogout((string) $granted['access_token']);

    $second->assertStatus(401);

    expect($second->json('code'))->toBe('unauthenticated');
});

it('leaves another merchant\'s tokens untouched', function (): void {
    $client = Authorization::bffClient();

    [, $mine] = loggedInMerchant($client);
    [, $theirs] = loggedInMerchant($client);

    $theirAccessTokenId = (string) Authorization::claims((string) $theirs['access_token'])['jti'];

    callLogout((string) $mine['access_token'])->assertNoContent();

    expect(DB::table('oauth_access_tokens')->where('id', $theirAccessTokenId)->value('revoked'))->toBeFalsy()
        ->and(DB::table('oauth_refresh_tokens')->where('access_token_id', $theirAccessTokenId)->value('revoked'))->toBeFalsy();

    callPayments((string) $theirs['access_token'])->assertStatus(404);

    Authorization::refresh($client, (string) $theirs['refresh_token'])->assertOk();
});

it('never touches the merchant authorization session, which is already spent', function (): void {
    [, $granted] = loggedInMerchant();

    expect(auth()->guard('merchant_web')->check())->toBeFalse();

    callLogout((string) $granted['access_token'])->assertNoContent();

    expect(auth()->guard('merchant_web')->check())->toBeFalse();
});

it('opens the revocation transaction on the connection the Passport models resolve', function (): void {
    config([
        'database.connections.pgsql_passport' => config('database.connections.pgsql'),
        'passport.connection' => 'pgsql_passport',
    ]);

    DB::purge('pgsql_passport');

    $opened = [];

    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$opened): void {
        $opened[] = $event->connectionName;
    });

    resolve(MerchantTokenRevocation::class)->revoke('an-access-token-id');

    expect(Passport::token()->getConnection()->getName())->toBe('pgsql_passport')
        ->and($opened)->toBe(['pgsql_passport']);

    DB::purge('pgsql_passport');
});
