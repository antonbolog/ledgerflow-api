<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Http\Requests\InitiatePaymentRequest;
use Modules\Payments\Tests\Support\Initiation;

/*
|--------------------------------------------------------------------------
| POST /api/payments — authentication, validation, ownership
|--------------------------------------------------------------------------
|
| BOUNDARY BEHAVIOUR ONLY. A 202 here proves that a request was ACCEPTED BY THE
| BOUNDARY: authenticated, validated, canonicalised, and owned by the token's
| merchant. It proves NOTHING about persistence, and no test in this file asserts
| a receipt, a stored event or an outbox row — even though an accepted request now
| writes all three. Persistence evidence belongs to InitiatePaymentWriteTest; a
| green run here must not be read as evidence that initiation persists correctly.
|
| Every request authenticates with a REAL Sanctum bearer token — see
| Modules\Payments\Tests\Support\Initiation for why actingAs is refused.
|
*/

it('refuses an unauthenticated initiation with problem details and no framework body', function (): void {
    $response = $this->postJson('/api/payments', Initiation::body(), ['Idempotency-Key' => Initiation::KEY]);

    $response->assertStatus(401);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('unauthenticated')
        ->and($response->json('instance'))->toBe('/api/payments')
        // Handler::unauthenticated() returns {"message":"Unauthenticated."}. If that
        // key survives, the respond() finaliser did not run.
        ->and($response->json())->not->toHaveKey('message');
});

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
    // "1250" would canonicalise to the same value, so this is not a correctness
    // fix. It is a refusal to accept a payload the contract does not describe, in
    // an API where a client type bug is worth surfacing rather than absorbing.
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
    // BYTES, not characters. The specification says 1-255 bytes, and a multibyte
    // key would pass a character check while overflowing the intent.
    $headers = $length === 0 ? [] : ['Idempotency-Key' => str_repeat('k', $length)];

    Initiation::send([], $headers)->assertStatus($status);
})->with([
    [0, 422],
    [1, 202],
    [255, 202],
    [256, 422],
]);

it('names the idempotency key in the validation errors', function (): void {
    // The header is validated under a snake_case input name so the problem
    // document can report it in `errors` like any other field.
    $response = Initiation::send([], []);

    expect($response->json('errors'))->toHaveKey('idempotency_key');
});

it('turns a malformed json body into a 422 problem document, not a 400 or a 500', function (): void {
    // Request::json() is `(array) json_decode(...)`, so malformed JSON decodes to
    // null, casts to [], and throws NOTHING. The result is a validation failure,
    // which is a defensible contract but an unobvious one — pin it, so a future
    // JSON-parse guard cannot silently introduce a non-problem+json 400.
    //
    // call() does not apply withHeader() defaults, so every header is passed as a
    // server variable here.
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
    // 422, not a silent override: a client must never believe it selected an owner
    // and be quietly overruled.
    //
    // WHY `missing` AND NOT `prohibited`: validateProhibited() is literally
    // `! validateRequired()`, and validateRequired() treats null and '' as absent —
    // so `prohibited` PASSES for an explicitly supplied null or empty merchant_id.
    // The contract is true absence, which is validateMissing()'s
    // `! Arr::has($this->data, $attribute)`. The null and empty rows below are the
    // ones `prohibited` let through.
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
    // A 202 alone is worthless here: it stays green if the controller never asks
    // who owns the request at all. A recording decorator around the REAL
    // MerchantContext is what makes deleting that call fail — it asserts both the
    // call count and the resolved identity, and the identity can only come from the
    // Sanctum token because the decorator delegates to the production
    // implementation.
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
    // The ASCII boundary rows above cannot tell strlen() from mb_strlen(). These
    // can. '€' is three UTF-8 bytes, so 85 of them are exactly 255 bytes and 86
    // characters — a character-based check would ACCEPT the 256-byte row below,
    // which is the mutation this test exists to catch.
    Initiation::send([], ['Idempotency-Key' => $key])->assertStatus($status);
})->with([
    '255 utf-8 bytes' => [str_repeat('€', 85), 202],
    '256 utf-8 bytes' => [str_repeat('€', 85).'k', 422],
]);

it('exposes canonical typed accessors for the command context', function (): void {
    // The immutable command context is built from these three methods, so they are
    // pinned directly rather than inferred from a downstream response. The probe
    // route resolves the REAL FormRequest through the REAL validation pipeline and
    // returns the accessors verbatim — which is what makes this independent of the
    // production controller's own response, whose shape is a separate contract that
    // could change without these accessors changing.
    Route::middleware('api')->post('/api/_request_probe', fn (InitiatePaymentRequest $request) => response()->json([
        'amount' => $request->canonicalAmountMinor(),
        'currency' => $request->canonicalCurrency(),
        'key' => $request->rawIdempotencyKey(),
    ]));

    $rawKey = 'Mixed-Case_Key.42~';

    $response = $this->postJson('/api/_request_probe', [
        'amount_minor' => 1_250,
        // Lower case in: the accessor must return the CANONICAL value, which proves
        // prepareForValidation() ran rather than the accessor upper-casing on read.
        'currency' => 'usd',
    ], ['Idempotency-Key' => $rawKey]);

    $response->assertStatus(200);

    expect($response->json('amount'))->toBe(1_250)
        ->and($response->json('currency'))->toBe('USD')
        // Byte-identical. The key is hashed, never transformed, so any normalisation
        // here would change which requests are considered the same one.
        ->and($response->json('key'))->toBe($rawKey);
});
