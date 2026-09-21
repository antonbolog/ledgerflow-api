<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Application\Identity\NoAuthenticatedMerchantUser;
use Modules\Merchant\Database\Seeders\PersonalAccessClientSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

function actAsBearerOf(User $user): void
{
    test()->seed(PersonalAccessClientSeeder::class);

    $request = Request::create('/', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$user->createToken('test')->accessToken);
    app()->instance('request', $request);

    // A guard resolved before the request swap would never see the header, and the token guard memoises its user.
    Auth::forgetGuards();
}

it('stores a morph alias rather than a class name', function (): void {
    expect(User::factory()->create()->getMorphClass())->toBe('merchant_user');
});

it('authenticates a merchant user from a real bearer token', function (): void {
    $merchant = Merchant::factory()->create();
    $user = User::factory()->for($merchant)->create();

    actAsBearerOf($user);

    expect(Auth::guard('api')->user()?->getKey())->toBe($user->getKey())
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

    actAsBearerOf(User::factory()->for($first)->create());
    expect($context->currentMerchantId())->toBe($first->getKey());

    actAsBearerOf(User::factory()->for($second)->create());
    expect($context->currentMerchantId())->toBe($second->getKey());
});
