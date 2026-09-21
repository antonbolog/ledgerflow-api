<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Modules\Merchant\Database\Seeders\PersonalAccessClientSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

it('prints only a Passport bearer token that authenticates the named user', function (): void {
    $user = User::factory()->for(Merchant::factory())->create(['email' => 'owner@northwind.test']);
    $this->seed(PersonalAccessClientSeeder::class);

    expect(Artisan::call('merchant:demo-token', ['email' => 'owner@northwind.test']))->toBe(0);

    $lines = array_values(array_filter(explode(PHP_EOL, Artisan::output())));

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/');

    $request = Request::create('/', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$lines[0]);
    app()->instance('request', $request);
    Auth::forgetGuards();

    expect(Auth::guard('api')->id())->toBe($user->getKey());
});

it('creates one personal access client and reuses it', function (): void {
    $user = User::factory()->for(Merchant::factory())->create();

    $this->seed(PersonalAccessClientSeeder::class);
    $this->seed(PersonalAccessClientSeeder::class);

    $user->createToken('first');
    $user->createToken('second');

    $clients = Passport::client()->newQuery()->get();

    expect($clients)->toHaveCount(1)
        ->and($clients->sole()->name)->toBe(PersonalAccessClientSeeder::CLIENT_NAME)
        ->and($clients->sole()->provider)->toBe('users')
        ->and($clients->sole()->grant_types)->toBe(['personal_access'])
        ->and(Passport::token()->newQuery()->where('user_id', $user->getKey())->count())->toBe(2);
});

it('fails for an unknown email without minting a token', function (): void {
    $this->seed(PersonalAccessClientSeeder::class);

    expect(Artisan::call('merchant:demo-token', ['email' => 'nobody@example.test']))->toBe(1)
        ->and(Passport::token()->newQuery()->count())->toBe(0);
});

it('fails with guidance when no personal access client was seeded', function (): void {
    User::factory()->for(Merchant::factory())->create(['email' => 'owner@northwind.test']);

    expect(Artisan::call('merchant:demo-token', ['email' => 'owner@northwind.test']))->toBe(1)
        ->and(Artisan::output())->toContain('make db-seed')
        ->and(Passport::token()->newQuery()->count())->toBe(0);
});

it('refuses to create the personal access client outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // --force, so the seeder's own guard refuses rather than Laravel's confirmation prompt.
    expect(fn () => $this->artisan('db:seed', ['--class' => PersonalAccessClientSeeder::class, '--force' => true]))
        ->toThrow(RuntimeException::class, 'outside the local and testing environments');

    expect(Passport::client()->newQuery()->count())->toBe(0)
        ->and(Passport::token()->newQuery()->count())->toBe(0);
});
