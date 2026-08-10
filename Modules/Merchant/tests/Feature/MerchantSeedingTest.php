<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Modules\Merchant\Database\Seeders\MerchantDatabaseSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

it('refuses to run outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    // --force on purpose, and it is the whole point of the test. SeedCommand
    // uses ConfirmableTrait, so without the flag Laravel's own prompt aborts
    // first and this guard is never reached — which would make the test pass
    // while proving nothing about the guard. `make db-seed` passes --force, so
    // this is also the exact path the command contract takes.
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

    // 'password' is the value the framework's own factory uses and the value a
    // reader of a public repository would try first.
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
        // Same ids and the same password hash: a re-seed must not rewrite
        // credentials or invalidate anything referencing these rows.
        ->and($after->toArray())->toBe($before->toArray());
});

it('refuses to move an existing user to a fixture merchant', function (): void {
    $other = Merchant::factory()->create();
    User::factory()->for($other)->create(['email' => 'owner@northwind.test']);

    expect(fn () => $this->seed(MerchantDatabaseSeeder::class))->toThrow(RuntimeException::class);
});

it('rolls back the complete fixture set when it refuses', function (): void {
    // The conflict is on the SECOND identity, and that is the whole point. By
    // the time it fires, the Northwind merchant and user are fully written and
    // the Atlantic merchant has just been created. A transaction that wrapped
    // only one iteration, or no transaction at all, would leave all of that
    // behind. Putting the conflict on the first identity proves almost nothing,
    // because there is no completed work to roll back yet.
    $unrelated = Merchant::factory()->create(['slug' => 'unrelated-holdings']);
    $existing = User::factory()->for($unrelated)->create(['email' => 'owner@atlantic.test']);
    $originalName = $existing->name;

    expect(fn () => $this->seed(MerchantDatabaseSeeder::class))->toThrow(RuntimeException::class);

    expect(Merchant::query()->where('slug', 'northwind-trading')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'owner@northwind.test')->exists())->toBeFalse()
        ->and(Merchant::query()->where('slug', 'atlantic-goods')->exists())->toBeFalse()
        // Only the rows that existed before the seeder ran survive it.
        ->and(Merchant::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);

    // The pre-existing user keeps its own merchant and its own name: the
    // seeder refuses before mutating, and the rollback would undo it regardless.
    $existing->refresh();

    expect($existing->merchant_id)->toBe($unrelated->id)
        ->and($existing->name)->toBe($originalName)
        ->and(Merchant::query()->where('slug', 'unrelated-holdings')->exists())->toBeTrue();
});

it('reaches the seeded identities through the root DatabaseSeeder', function (): void {
    $this->seed();

    expect(Merchant::query()->count())->toBe(2);
});
