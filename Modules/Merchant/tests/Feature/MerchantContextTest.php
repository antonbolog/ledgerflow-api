<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Application\Identity\NoAuthenticatedMerchantUser;
use Modules\Merchant\Infrastructure\Identity\AuthenticatedMerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

it('stores only the hash of an issued token', function (): void {
    $user = User::factory()->create();
    $plainText = $user->createToken('test')->plainTextToken;

    expect(PersonalAccessToken::query()->where('token', $plainText)->exists())->toBeFalse()
        ->and(PersonalAccessToken::findToken($plainText)?->tokenable_id)->toBe($user->getKey());
});

it('stores a morph alias rather than a class name', function (): void {
    // The alias is what lets the User class move again without a data
    // migration. Asserted here because nothing else would notice its removal.
    expect(User::factory()->create()->getMorphClass())->toBe('merchant_user');
});

it('authenticates a merchant user from a real bearer token', function (): void {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->for($merchant)->create();
    $token = $user->createToken('test')->plainTextToken;

    $request = Request::create('/', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$token);
    app()->instance('request', $request);

    // forgetGuards is required, not decorative: the sanctum RequestGuard is
    // constructed with whatever request was in the container at the time, so a
    // guard resolved before this swap would never see the header.
    //
    // This path is what actingAs() does NOT test. actingAs installs the user
    // straight into the guard, so it would still pass with no
    // personal_access_tokens table at all.
    Auth::forgetGuards();

    expect(Auth::guard('sanctum')->user()?->getKey())->toBe($user->getKey())
        ->and(resolve(MerchantContext::class)->currentMerchantId())->toBe($merchant->getKey());
});

it('refuses to invent a merchant when nobody is authenticated', function (): void {
    expect(fn () => resolve(MerchantContext::class)->currentMerchantId())
        ->toThrow(NoAuthenticatedMerchantUser::class);
});

it('does not cache the merchant across authenticated users', function (): void {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();
    $context = resolve(MerchantContext::class);

    Sanctum::actingAs(User::factory()->for($first)->create());
    expect($context->currentMerchantId())->toBe($first->getKey());

    Sanctum::actingAs(User::factory()->for($second)->create());
    expect($context->currentMerchantId())->toBe($second->getKey());
});

it('is bound to the Sanctum-aware implementation', function (): void {
    expect(resolve(MerchantContext::class))->toBeInstanceOf(AuthenticatedMerchantContext::class);
});
