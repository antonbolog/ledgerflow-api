<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

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
    $original = Merchant::factory()->create();
    $other = Merchant::factory()->create();
    $user = User::factory()->for($original)->create();

    $user->update(['name' => 'Renamed', 'merchant_id' => $other->id]);
    $user->fill(['merchant_id' => $other->id]);
    $user->save();
    $user->refresh();

    expect($user->name)->toBe('Renamed')
        ->and($user->merchant_id)->toBe($original->id)
        ->and($user->getFillable())->not->toContain('merchant_id');
});

it('indexes the merchant foreign key', function (): void {
    expect(Schema::hasIndex('users', 'users_merchant_id_index'))->toBeTrue();
});
