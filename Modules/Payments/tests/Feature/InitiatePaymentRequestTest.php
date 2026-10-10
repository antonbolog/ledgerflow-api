<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Http\Requests\InitiatePaymentRequest;
use Modules\Payments\Http\Requests\PaymentIntentHeaders;
use Modules\Payments\Tests\Support\Initiation;

it('accepts the inclusive amount boundaries', function (int $amount): void {
    Initiation::send(['amount_minor' => $amount])->assertStatus(202);
})->with([1, 100_000_000]);

it('rejects amounts outside the inclusive range', function (int $amount): void {
    $response = Initiation::send(['amount_minor' => $amount]);

    $response->assertStatus(422);

    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('amount_minor');
})->with([0, 100_000_001, -1]);

it('rejects an amount that is not a json integer', function (mixed $amount): void {
    Initiation::send(['amount_minor' => $amount])->assertStatus(422);
})->with(['1250', 1250.5]);

it('canonicalises currency case and accepts only usd', function (string $currency, int $status): void {
    Initiation::send(['currency' => $currency])->assertStatus($status);
})->with([
    ['USD', 202],
    ['usd', 202],
    ['Usd', 202],
    ['EUR', 422],
    ['US', 422],
]);

it('covers the idempotency key length boundaries', function (int $length, int $status): void {
    $headers = $length === 0 ? [] : ['Idempotency-Key' => str_repeat('k', $length)];

    Initiation::send([], $headers)->assertStatus($status);
})->with([
    [0, 422],
    [1, 202],
    [255, 202],
    [256, 422],
]);

it('names the idempotency key in the validation errors', function (): void {
    $response = Initiation::send([], []);

    expect($response->json('errors'))->toHaveKey('idempotency_key');
});

it('turns a malformed json body into a 422 problem document, not a 400 or a 500', function (): void {
    // call() ignores withHeader() defaults, so headers are passed as server variables.
    $response = $this->call('POST', '/api/payments', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.Initiation::token(),
        'HTTP_IDEMPOTENCY_KEY' => Initiation::KEY,
    ], '{"amount_minor": 1250,');

    $response->assertStatus(422);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('amount_minor');
});

it('refuses a client-supplied merchant_id in any form, present is present', function (mixed $supplied): void {
    $response = Initiation::send(['merchant_id' => $supplied]);

    $response->assertStatus(422);

    expect($response->json('code'))->toBe('validation_failed')
        ->and($response->json('errors'))->toHaveKey('merchant_id');
})->with([
    'integer' => fn () => Merchant::factory()->create()->getKey(),
    'null' => null,
    'empty string' => '',
]);

it('resolves the merchant from the bearer token exactly once', function (): void {
    $merchant = Merchant::factory()->create();

    $real = $this->app->make(MerchantContext::class);

    $spy = new class($real) implements MerchantContext
    {
        /** @var list<int> */
        public array $resolved = [];

        public function __construct(private readonly MerchantContext $inner) {}

        public function currentMerchantId(): int
        {
            $id = $this->inner->currentMerchantId();

            $this->resolved[] = $id;

            return $id;
        }
    };

    $this->app->instance(MerchantContext::class, $spy);

    Initiation::send(merchant: $merchant)->assertStatus(202);

    expect($spy->resolved)->toBe([$merchant->getKey()]);
});

it('validates the idempotency key in bytes, not characters', function (string $key, int $status): void {
    // '€' is three UTF-8 bytes, so only multibyte keys separate strlen() from mb_strlen().
    Initiation::send([], ['Idempotency-Key' => $key])->assertStatus($status);
})->with([
    '255 utf-8 bytes' => [str_repeat('€', 85), 202],
    '256 utf-8 bytes' => [str_repeat('€', 85).'k', 422],
]);

it('exposes canonical typed accessors for the command context', function (): void {
    Route::middleware('api')->post('/api/_request_probe', fn (InitiatePaymentRequest $request) => response()->json([
        'amount' => $request->canonicalAmountMinor(),
        'currency' => $request->canonicalCurrency(),
        'key' => $request->rawIdempotencyKey(),
        'intent' => $request->intentId(),
        'expires_at' => $request->intentExpiresAt(),
    ]));

    $rawKey = 'Mixed-Case_Key.42~';
    $headers = Initiation::headers(['Idempotency-Key' => $rawKey]);

    $response = $this->postJson('/api/_request_probe', [
        'amount_minor' => 1_250,
        'currency' => 'usd',
    ], $headers);

    $response->assertStatus(200);

    expect($response->json('amount'))->toBe(1_250)
        ->and($response->json('currency'))->toBe('USD')
        ->and($response->json('key'))->toBe($rawKey)
        ->and($response->json('intent'))->toBe($headers[PaymentIntentHeaders::INTENT_ID])
        ->and($response->json('expires_at'))->toBe($headers[PaymentIntentHeaders::EXPIRES_AT]);
});
