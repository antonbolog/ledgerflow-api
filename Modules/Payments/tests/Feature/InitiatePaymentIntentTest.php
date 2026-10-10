<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\PaymentIntentDeadlineRefused;
use Modules\Payments\Application\Initiation\PaymentIntentSessionTerminated;
use Modules\Payments\Http\Requests\PaymentIntentHeaders;
use Modules\Payments\Infrastructure\Persistence\DatabasePaymentIntentDeadline;
use Modules\Payments\Tests\Support\Initiation;

// RefreshDatabase keeps each test inside one transaction, so a deadline armed by a request stays armed until the test
// ends; scenarios that outlive an expiry belong in the Integration suite.

/** @param  array<string, string>  $headers  sent exactly as given */
function intentPost(array $headers, Merchant $merchant, array $payload = [], ?string $token = null): TestResponse
{
    $token ??= Initiation::token($merchant);

    Auth::forgetGuards();

    return test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/payments', [...Initiation::body(), ...$payload], $headers);
}

/** @return array<string, string> */
function intentHeaders(string $key, string $intentId, ?string $expiresAt = null): array
{
    return [
        'Idempotency-Key' => $key,
        PaymentIntentHeaders::INTENT_ID => $intentId,
        PaymentIntentHeaders::EXPIRES_AT => $expiresAt ?? intentExpiryIn(30_000),
    ];
}

function intentExpiryIn(int $milliseconds): string
{
    return (string) DB::selectOne(
        "select to_char((clock_timestamp() + make_interval(secs => ?::float8 / 1000)) at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') as expiry",
        [$milliseconds],
    )->expiry;
}

/** @return array{int, int, int} */
function intentWrites(): array
{
    return [
        DB::table('payment_idempotency_receipts')->count(),
        DB::table('stored_events')->count(),
        DB::table('payment_outbox')->count(),
    ];
}

it('refuses a POST without either intent header with 422 and writes nothing', function (): void {
    $merchant = Merchant::factory()->create();

    $response = intentPost(['Idempotency-Key' => 'headerless'], $merchant);

    $response->assertStatus(422);

    expect($response->json('code'))->toBe('validation_failed')
        ->and(array_keys((array) $response->json('errors')))->toEqualCanonicalizing(['intent_id', 'intent_expires_at'])
        ->and(intentWrites())->toBe([0, 0, 0]);
});

it('refuses a headerless retry of a historical receipt with 422 instead of replaying it', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();
    $now = now();

    DB::table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $merchant->getKey(),
        'idempotency_key_hash' => PaymentInitiationContext::hashKey('historical'),
        'request_fingerprint' => PaymentInitiationContext::fingerprint($merchant->getKey(), 1_250, 'USD'),
        'payment_id' => $paymentId,
        'response_status' => 202,
        'response_body' => json_encode(['payment_id' => $paymentId, 'status' => 'initiated']),
        'response_location' => '/api/payments/'.$paymentId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    intentPost(['Idempotency-Key' => 'historical'], $merchant)->assertStatus(422);

    expect(intentWrites())->toBe([1, 0, 0]);
});

it('refuses a :dataset intent id at the HTTP boundary', function (string $value): void {
    $merchant = Merchant::factory()->create();

    $response = intentPost([...intentHeaders('spelling', (string) Str::uuid()), PaymentIntentHeaders::INTENT_ID => $value], $merchant);

    $response->assertStatus(422);

    expect(array_keys((array) $response->json('errors')))->toBe(['intent_id'])
        ->and(intentWrites())->toBe([0, 0, 0]);
})->with([
    'uppercase' => [strtoupper('0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d')],
    'braced' => ['{0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d}'],
    'whitespace-surrounded' => [' 0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d '],
    'non-UUID' => ['intent-1'],
    'empty' => [''],
    'repeated' => ['0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d, 0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0e'],
]);

it('refuses an expiry that is :dataset at the HTTP boundary', function (Closure $expiry): void {
    $merchant = Merchant::factory()->create();

    $response = intentPost(intentHeaders('expiry', (string) Str::uuid(), $expiry()), $merchant);

    $response->assertStatus(422);

    expect(array_keys((array) $response->json('errors')))->toBe(['intent_expires_at'])
        ->and(intentWrites())->toBe([0, 0, 0]);
})->with([
    'unparsable' => [fn (): string => 'tomorrow'],
    'without milliseconds' => [fn (): string => CarbonImmutable::now('UTC')->addSeconds(10)->format('Y-m-d\TH:i:s\Z')],
    'repeated' => [fn (): string => intentExpiryIn(10_000).', '.intentExpiryIn(10_000)],
    'beyond the 32 s cap' => [fn (): string => intentExpiryIn(60_000)],
    'in year zero' => [fn (): string => '0000-01-01T00:00:00.000Z'],
]);

it('pins the expiry cap within half a second on both sides', function (): void {
    $merchant = Merchant::factory()->create();
    // Minted before stamping, so token signing cannot spend the half second of slack.
    $token = Initiation::token($merchant);

    intentPost(intentHeaders('under-cap', (string) Str::uuid(), intentExpiryIn(DatabasePaymentIntentDeadline::CAP_MS - 500)), $merchant, token: $token)
        ->assertStatus(202);

    $over = intentPost(intentHeaders('over-cap', (string) Str::uuid(), intentExpiryIn(DatabasePaymentIntentDeadline::CAP_MS + 500)), $merchant, token: $token);

    $over->assertStatus(422);

    expect(array_keys((array) $over->json('errors')))->toBe(['intent_expires_at'])
        ->and(intentWrites())->toBe([1, 1, 1]);
});

it('takes the intent only from its headers, never from the JSON body', function (): void {
    $merchant = Merchant::factory()->create();

    $smuggled = intentPost(['Idempotency-Key' => 'body-only'], $merchant, [
        'intent_id' => (string) Str::uuid(),
        'intent_expires_at' => intentExpiryIn(10_000),
    ]);

    $smuggled->assertStatus(422);

    expect(array_keys((array) $smuggled->json('errors')))->toEqualCanonicalizing(['intent_id', 'intent_expires_at'])
        ->and(intentWrites())->toBe([0, 0, 0]);

    $headerIntent = (string) Str::uuid();

    intentPost(intentHeaders('both', $headerIntent), $merchant, ['intent_id' => (string) Str::uuid()])->assertStatus(202);

    expect(DB::table('payment_idempotency_receipts')->value('intent_id'))->toBe($headerIntent);
});

it('replays a headered retry of a receipt written before intents existed, and refuses one with another payload', function (): void {
    $merchant = Merchant::factory()->create();
    $intent = (string) Str::uuid();

    $first = intentPost(intentHeaders('straddling', $intent), $merchant);
    $first->assertStatus(202);

    // What a worker without ANT-35 writes for the same request: it ignores the intent headers.
    DB::table('payment_idempotency_receipts')->update(['intent_id' => null]);

    intentPost(intentHeaders('straddling', $intent), $merchant, ['amount_minor' => 9_999])->assertStatus(409);

    $retry = intentPost(intentHeaders('straddling', $intent), $merchant);

    $retry->assertStatus(202);

    expect($retry->getContent())->toBe($first->getContent())
        ->and($retry->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(intentWrites())->toBe([1, 1, 1]);
});

it('replays the same intent under a new key byte for byte, with one write of each kind', function (): void {
    $merchant = Merchant::factory()->create();
    $intent = (string) Str::uuid();

    $first = intentPost(intentHeaders('key-1', $intent), $merchant);
    $second = intentPost(intentHeaders('key-2', $intent), $merchant);

    $first->assertStatus(202);
    $second->assertStatus(202);

    expect($second->getContent())->toBe($first->getContent())
        ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and(intentWrites())->toBe([1, 1, 1]);
});

it('refuses :dataset with 409 and writes nothing new', function (Closure $conflict): void {
    $merchant = Merchant::factory()->create();

    $conflict($merchant)->assertStatus(409)->assertJsonPath('code', 'idempotency_payload_mismatch');

    expect(intentWrites())->toBe([1, 1, 1]);
})->with([
    'the same intent with a different payload' => [function (Merchant $merchant): TestResponse {
        $intent = (string) Str::uuid();
        intentPost(intentHeaders('payload-1', $intent), $merchant)->assertStatus(202);

        return intentPost(intentHeaders('payload-2', $intent), $merchant, ['amount_minor' => 999]);
    }],
    'the same key bound to a different intent' => [function (Merchant $merchant): TestResponse {
        intentPost(intentHeaders('shared-key', (string) Str::uuid()), $merchant)->assertStatus(202);

        return intentPost(intentHeaders('shared-key', (string) Str::uuid()), $merchant);
    }],
]);

it('refuses a crossed binding with 409 when the key and the intent belong to two payments', function (): void {
    $merchant = Merchant::factory()->create();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();

    intentPost(intentHeaders('k1', $first), $merchant)->assertStatus(202);
    intentPost(intentHeaders('k2', $second), $merchant)->assertStatus(202);

    intentPost(intentHeaders('k2', $first), $merchant)
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_payload_mismatch');

    expect(intentWrites())->toBe([2, 2, 2]);
});

it('creates independent payments for two merchants using one intent id', function (): void {
    $intent = (string) Str::uuid();

    $first = intentPost(intentHeaders('same', $intent), Merchant::factory()->create());
    $second = intentPost(intentHeaders('same', $intent), Merchant::factory()->create());

    $first->assertStatus(202);
    $second->assertStatus(202);

    expect($second->json('payment_id'))->not->toBe($first->json('payment_id'))
        ->and(intentWrites())->toBe([2, 2, 2]);
});

it('answers an expired intent with no payment :dataset and writes nothing', function (int $offsetMs, int $status, ?string $retryAfter): void {
    $merchant = Merchant::factory()->create();
    $token = Initiation::token($merchant);

    // Stamped after the token exists, so request setup cannot eat into the window under test.
    $response = intentPost(intentHeaders('late', (string) Str::uuid(), intentExpiryIn($offsetMs)), $merchant, token: $token);

    $response->assertStatus($status);

    expect((string) $response->headers->get('Retry-After'))->toMatch($retryAfter ?? '/^$/')
        ->and(intentWrites())->toBe([0, 0, 0]);

    if ($status === 422) {
        expect(array_keys((array) $response->json('errors')))->toBe(['intent_expires_at']);
    } else {
        expect($response->json('code'))->toBe('idempotency_contention');
    }
})->with([
    'with 503 while the residual allowance runs' => [-200, 503, '/^1$/'],
    // Up to 1200 ms until settled, minus request time: one or two whole seconds.
    'with 503 inside the refusal margin, until the outcome settles' => [200, 503, '/^[12]$/'],
    'as settled and rejected after the residual allowance' => [-5_000, 422, null],
]);

it('answers a session ended at the deadline as unsettled 503 contention, retryable after 1 second', function (): void {
    Route::middleware('api')->post('/api/_intent_probe', fn () => throw PaymentIntentSessionTerminated::afterConnectionLoss());

    $response = test()->postJson('/api/_intent_probe');

    $response->assertStatus(503);

    expect($response->json('code'))->toBe('idempotency_contention')
        ->and($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->getContent())->not->toContain('session');
});

it('reports a session ended by the deadline but not a routine deadline refusal', function (): void {
    $handler = resolve(ExceptionHandler::class);

    expect($handler->shouldReport(PaymentIntentDeadlineRefused::beforeWork(0)))->toBeFalse()
        ->and($handler->shouldReport(PaymentIntentDeadlineRefused::beforeCommit(500)))->toBeFalse()
        ->and($handler->shouldReport(PaymentIntentSessionTerminated::afterConnectionLoss()))->toBeTrue();
});
