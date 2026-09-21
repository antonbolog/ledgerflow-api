<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Merchant\Database\Seeders\MerchantDatabaseSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

const DEMO_PASSWORD = 'local-fixture-password';

function fixtureMerchantUser(): User
{
    return User::factory()->for(Merchant::factory())->create([
        'email' => MerchantDatabaseSeeder::fixtureEmails()[0],
    ]);
}

it('sets a fixture password from the hidden prompt', function (): void {
    $user = fixtureMerchantUser();

    $this->artisan('merchant:demo-password', ['email' => $user->email])
        ->expectsQuestion('New password', DEMO_PASSWORD)
        ->expectsQuestion('Confirm password', DEMO_PASSWORD)
        ->assertSuccessful();

    expect(Hash::check(DEMO_PASSWORD, (string) $user->fresh()?->password))->toBeTrue()
        ->and(Auth::guard('merchant_web')->attempt(['email' => $user->email, 'password' => DEMO_PASSWORD]))->toBeTrue();
});

it('offers no way to pass the password outside that prompt', function (): void {
    $definition = $this->app->make(Kernel::class)->all()['merchant:demo-password']->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and(array_keys($definition->getOptions()))->not->toContain('password');
});

it('refuses an email that is not a seeded fixture identity', function (): void {
    $user = User::factory()->for(Merchant::factory())->create(['email' => 'real.merchant@example.com']);
    $before = (string) $user->password;

    $this->artisan('merchant:demo-password', ['email' => $user->email])->assertFailed();

    expect((string) $user->fresh()?->password)->toBe($before);
});

it('refuses a fixture identity that does not exist here', function (): void {
    $this->artisan('merchant:demo-password', ['email' => MerchantDatabaseSeeder::fixtureEmails()[1]])
        ->assertFailed();
});

it('refuses a mismatched confirmation', function (): void {
    $user = fixtureMerchantUser();
    $before = (string) $user->password;

    $this->artisan('merchant:demo-password', ['email' => $user->email])
        ->expectsQuestion('New password', DEMO_PASSWORD)
        ->expectsQuestion('Confirm password', DEMO_PASSWORD.'!')
        ->assertFailed();

    expect((string) $user->fresh()?->password)->toBe($before);
});

it('refuses a password it never got or that is too short', function (string $entered): void {
    $user = fixtureMerchantUser();
    $before = (string) $user->password;

    $this->artisan('merchant:demo-password', ['email' => $user->email])
        ->expectsQuestion('New password', $entered)
        ->assertFailed();

    expect((string) $user->fresh()?->password)->toBe($before);
})->with([
    'too few characters' => 'short',
    'nothing at all' => '',
]);
