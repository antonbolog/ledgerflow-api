<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use Tests\TestCase;

it('runs module tests with the application test case and a fresh database', function (): void {
    // If tests/Pest.php stops matching this directory, module tests still run
    // but without TestCase or RefreshDatabase, and every later failure is a
    // confusing symptom of that one cause. This names it directly.
    expect($this)->toBeInstanceOf(TestCase::class)
        ->and(class_uses_recursive($this))->toContain(RefreshDatabase::class);
});

it('resolves the authenticatable user from the Merchant module', function (): void {
    expect(config('auth.providers.users.model'))->toBe(User::class);
});

it('refuses a duplicate merchant slug', function (): void {
    Merchant::factory()->create(['slug' => 'northwind-trading']);

    expect(fn () => Merchant::factory()->create(['slug' => 'northwind-trading']))
        ->toThrow(QueryException::class);
});

it('binds a user to its merchant', function (): void {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->for($merchant)->create();

    expect($user->merchant->getKey())->toBe($merchant->getKey())
        ->and($merchant->users()->count())->toBe(1);
});

it('requires every user to name a merchant', function (): void {
    expect(fn () => User::factory()->create(['merchant_id' => null]))
        ->toThrow(QueryException::class);
});

it('refuses to delete a merchant that still has users', function (): void {
    $merchant = Merchant::factory()->create();
    User::factory()->for($merchant)->create();

    expect(fn () => $merchant->delete())->toThrow(QueryException::class);
});

it('refuses to change merchant ownership through mass assignment', function (): void {
    // fill()/update() are how request input reaches a model. If merchant_id were
    // mass-assignable, a payload carrying it would move a user between tenants,
    // and the whole "the client cannot choose the merchant" rule would be a
    // convention rather than a guarantee.
    $original = Merchant::factory()->create();
    $other = Merchant::factory()->create();
    $user = User::factory()->for($original)->create();

    $user->update(['name' => 'Renamed', 'merchant_id' => $other->id]);
    $user->fill(['merchant_id' => $other->id]);
    $user->save();
    $user->refresh();

    // The benign attribute in the same payload still applies, which is what
    // makes this a mass-assignment guard rather than a rejected write.
    expect($user->name)->toBe('Renamed')
        ->and($user->merchant_id)->toBe($original->id)
        ->and($user->getFillable())->not->toContain('merchant_id');
});

it('indexes the merchant foreign key', function (): void {
    // PostgreSQL does not index a foreign key column, and every merchant-scoped
    // query filters on it. Nothing else in this suite would notice the index
    // disappearing, because the absence is silent and only costs latency.
    expect(Schema::hasIndex('users', 'users_merchant_id_index'))->toBeTrue();
});
