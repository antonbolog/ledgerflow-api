<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Modules\Merchant\Infrastructure\Console\ProvisionBffClientCommand;

const BFF_REDIRECT_URI = 'http://localhost:3000/auth/callback';

it('registers one confidential first-party client with only the two expected grants', function (): void {
    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertSuccessful();

    $client = Passport::client()->newQuery()->where('name', ProvisionBffClientCommand::CLIENT_NAME)->sole();

    expect($client->redirect_uris)->toBe([BFF_REDIRECT_URI])
        ->and($client->grant_types)->toEqualCanonicalizing(['authorization_code', 'refresh_token'])
        ->and($client->confidential())->toBeTrue()
        ->and($client->firstParty())->toBeTrue()
        ->and($client->revoked)->toBeFalse();
});

it('shows the secret once and stores only a hash of it', function (): void {
    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertSuccessful();

    $client = Passport::client()->newQuery()->where('name', ProvisionBffClientCommand::CLIENT_NAME)->sole();
    $stored = (string) DB::table('oauth_clients')->where('id', $client->getKey())->value('secret');

    expect($stored)->not->toBe('')
        ->and($client->fresh()?->plainSecret)->toBeNull()
        ->and(password_get_info($stored)['algo'])->not->toBeNull();
});

it('refuses a second active client rather than minting a secret for an existing name', function (): void {
    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertSuccessful();
    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertFailed();

    expect(Passport::client()->newQuery()->where('name', ProvisionBffClientCommand::CLIENT_NAME)->count())->toBe(1);
});

it('registers a successor with a new id and secret once the active client is revoked', function (): void {
    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertSuccessful();

    $retired = Passport::client()->newQuery()->where('name', ProvisionBffClientCommand::CLIENT_NAME)->sole();
    $retiredSecret = (string) DB::table('oauth_clients')->where('id', $retired->getKey())->value('secret');

    $retired->forceFill(['revoked' => true])->save();

    $this->artisan('merchant:bff-client', ['redirect-uri' => BFF_REDIRECT_URI])->assertSuccessful();

    $successor = Passport::client()->newQuery()
        ->where('name', ProvisionBffClientCommand::CLIENT_NAME)
        ->where('revoked', false)
        ->sole();

    $successorSecret = (string) DB::table('oauth_clients')->where('id', $successor->getKey())->value('secret');

    expect($successor->getKey())->not->toBe($retired->getKey())
        ->and($successorSecret)->not->toBe($retiredSecret)
        ->and(Passport::client()->newQuery()->where('name', ProvisionBffClientCommand::CLIENT_NAME)->count())->toBe(2)
        ->and(DB::table('oauth_clients')->where('id', $retired->getKey())->value('revoked'))->toBeTruthy();
});

it('refuses a redirect uri that OAuth would not match exactly', function (string $redirectUri): void {
    $this->artisan('merchant:bff-client', ['redirect-uri' => $redirectUri])->assertFailed();

    expect(Passport::client()->newQuery()->count())->toBe(0);
})->with([
    'a relative path' => '/auth/callback',
    // Loopback IP literals are matched without their port, which would widen the exact-match contract.
    'a loopback IPv4 address' => 'http://127.0.0.1:3000/auth/callback',
    'a loopback IPv6 address' => 'http://[::1]:3000/auth/callback',
    'a fragment' => 'http://localhost:3000/auth/callback#token',
]);
