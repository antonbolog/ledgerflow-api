<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Modules\Merchant\Database\Seeders\MerchantDatabaseSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

it('refuses to run outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // --force, so the seeder's own guard refuses rather than Laravel's confirmation prompt.
    $seed = fn () => $this->artisan('db:seed', [
        '--class' => MerchantDatabaseSeeder::class,
        '--force' => true,
    ]);

    expect($seed)->toThrow(RuntimeException::class)
        ->and(Merchant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('never creates users with a known password', function (): void {
    $this->seed(MerchantDatabaseSeeder::class);

    $user = User::query()->where('email', 'owner@northwind.test')->sole();

    expect(Hash::check('password', $user->password))->toBeFalse()
        ->and($user->password)->toStartWith('$2y$');
});

it('seeds two merchants, each owning one user', function (): void {
    $this->seed(MerchantDatabaseSeeder::class);

    expect(Merchant::query()->pluck('slug')->sort()->values()->all())
        ->toBe(['atlantic-goods', 'northwind-trading'])
        ->and(User::query()->count())->toBe(2);

    foreach (Merchant::query()->get() as $merchant) {
        expect($merchant->users()->count())->toBe(1);
    }
});

it('is repeatable without duplicating or mutating identities', function (): void {
    $this->seed(MerchantDatabaseSeeder::class);
    $before = User::query()->orderBy('email')->get(['id', 'email', 'merchant_id', 'password']);

    $this->seed(MerchantDatabaseSeeder::class);
    $after = User::query()->orderBy('email')->get(['id', 'email', 'merchant_id', 'password']);

    expect(Merchant::query()->count())->toBe(2)
        ->and($after->toArray())->toBe($before->toArray());
});

it('refuses to move an existing user to a fixture merchant', function (): void {
    $other = Merchant::factory()->create();
    User::factory()->for($other)->create(['email' => 'owner@northwind.test']);

    expect(fn () => $this->seed(MerchantDatabaseSeeder::class))->toThrow(RuntimeException::class);
});

it('rolls back the complete fixture set when it refuses', function (): void {
    $unrelated = Merchant::factory()->create(['slug' => 'unrelated-holdings']);
    $existing = User::factory()->for($unrelated)->create(['email' => 'owner@atlantic.test']);
    $originalName = $existing->name;

    expect(fn () => $this->seed(MerchantDatabaseSeeder::class))->toThrow(RuntimeException::class);

    expect(Merchant::query()->where('slug', 'northwind-trading')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'owner@northwind.test')->exists())->toBeFalse()
        ->and(Merchant::query()->where('slug', 'atlantic-goods')->exists())->toBeFalse()
        ->and(Merchant::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);

    $existing->refresh();

    expect($existing->merchant_id)->toBe($unrelated->id)
        ->and($existing->name)->toBe($originalName)
        ->and(Merchant::query()->where('slug', 'unrelated-holdings')->exists())->toBeTrue();
});

it('reaches the seeded identities through the root DatabaseSeeder', function (): void {
    $this->seed();

    expect(Merchant::query()->count())->toBe(2);
});
