<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Http\Requests\PaymentIntentHeaders;
use Modules\Payments\Tests\Support\CountingMerchantContext;
use Modules\Payments\Tests\Support\Initiation;

function recentIntents(?Merchant $merchant = null): TestResponse
{
    $token = Initiation::token($merchant);

    // The token guard memoises its user across requests in one test.
    Auth::forgetGuards();

    return test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/payment-intents');
}

/** @return array{intent_id: string, payment_id: string} */
function recentIntentReceipt(int $merchantId, string $createdAt): array
{
    $paymentId = (string) Str::uuid7();
    $intentId = (string) Str::uuid();

    DB::table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'idempotency_key_hash' => hash('sha256', $paymentId),
        'request_fingerprint' => str_repeat('b', 64),
        'payment_id' => $paymentId,
        'intent_id' => $intentId,
        'response_status' => 202,
        'response_body' => '{"payment_id":"'.$paymentId.'","status":"initiated"}',
        'response_location' => '/api/payments/'.$paymentId,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return ['intent_id' => $intentId, 'payment_id' => $paymentId];
}

function recentIntentProjection(int $merchantId, string $paymentId): void
{
    DB::table('payment_details')->insert([
        'payment_id' => $paymentId,
        'source_event_id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'amount_minor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'aggregate_version' => 1,
        'initiated_at' => '2026-08-21T10:11:12.123456+00:00',
    ]);
}

/** UTC instants with six fractional digits, `$secondsAgo` before PostgreSQL's clock. */
function recentIntentInstant(int $secondsAgo): string
{
    /** @var string $instant */
    $instant = DB::scalar(
        <<<'SQL'
            select to_char((statement_timestamp() - make_interval(secs => ?)) at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"')
            SQL,
        [$secondsAgo],
    );

    return $instant;
}

function recentIntentsCloseGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);
}

it('lists projected and pending intents newest first, in wire order, with no amount keys on pending ones', function (): void {
    $merchant = Merchant::factory()->create();

    $newer = recentIntentInstant(60);
    $older = recentIntentInstant(120);
    $projected = recentIntentReceipt($merchant->getKey(), $newer);
    $pending = recentIntentReceipt($merchant->getKey(), $older);
    recentIntentProjection($merchant->getKey(), $projected['payment_id']);

    $response = recentIntents($merchant);

    $response->assertStatus(200);

    expect($response->json())->toBe([
        'intents' => [
            [
                'intent_id' => $projected['intent_id'],
                'payment_id' => $projected['payment_id'],
                'created_at' => $newer,
                'amount_minor' => 1_250,
                'currency' => 'USD',
            ],
            [
                'intent_id' => $pending['intent_id'],
                'payment_id' => $pending['payment_id'],
                'created_at' => $older,
            ],
        ],
        'truncated' => false,
    ]);

    expect($response->json('intents.0.amount_minor'))->toBeInt()
        ->and($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertHeaderMissing('Retry-After');
});

it('answers an empty list as 200 with truncated false', function (): void {
    $response = recentIntents(Merchant::factory()->create());

    $response->assertStatus(200);

    expect($response->getContent())->toBe('{"intents":[],"truncated":false}');
});

it('never lists another merchant intents', function (): void {
    $owner = Merchant::factory()->create();
    $stranger = Merchant::factory()->create();

    $own = recentIntentReceipt($owner->getKey(), recentIntentInstant(60));
    recentIntentReceipt($stranger->getKey(), recentIntentInstant(30));

    expect(recentIntents($owner)->json('intents.*.payment_id'))->toBe([$own['payment_id']]);
});

it('resolves the merchant through MerchantContext exactly once', function (): void {
    $merchant = Merchant::factory()->create();
    recentIntentReceipt($merchant->getKey(), recentIntentInstant(60));

    $context = new CountingMerchantContext($merchant->getKey());
    app()->instance(MerchantContext::class, $context);

    expect(recentIntents($merchant)->assertStatus(200)->json('intents'))->toHaveCount(1)
        ->and($context->calls)->toBe(1);
});

it('answers 503 projection_rebuilding with Retry-After: 1 while the gate is closed', function (): void {
    $merchant = Merchant::factory()->create();
    recentIntentReceipt($merchant->getKey(), recentIntentInstant(60));

    recentIntentsCloseGate();

    $response = recentIntents($merchant);

    $response->assertStatus(503);

    expect($response->json())->toBe([
        'type' => '/problems/projection-rebuilding',
        'title' => 'Projection rebuilding',
        'status' => 503,
        'detail' => 'A projection rebuild is in progress. Retry.',
        'instance' => '/api/payment-intents',
        'code' => 'projection_rebuilding',
    ]);

    expect($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');

    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);

    expect(recentIntents($merchant)->assertStatus(200)->json('intents'))->toHaveCount(1);
});

it('reveals no key, hash, fingerprint, expiry or stored response bytes of a real initiation', function (): void {
    $merchant = Merchant::factory()->create();
    $key = 'recovery-scan-'.Str::random(24);
    $headers = Initiation::headers(['Idempotency-Key' => $key]);

    $accepted = Initiation::send(headers: $headers, merchant: $merchant);
    $accepted->assertStatus(202);

    /** @var object{idempotency_key_hash: string, request_fingerprint: string, response_body: string, intent_id: string, payment_id: string} $receipt */
    $receipt = DB::table('payment_idempotency_receipts')
        ->where('merchant_id', $merchant->getKey())
        ->sole(['idempotency_key_hash', 'request_fingerprint', 'response_body', 'intent_id', 'payment_id']);

    $secrets = [
        'raw key' => $key,
        'key hash' => $receipt->idempotency_key_hash,
        'fingerprint' => $receipt->request_fingerprint,
        'expiry' => $headers[PaymentIntentHeaders::EXPIRES_AT],
        'stored response bytes' => $receipt->response_body,
    ];

    // Non-vacuous: every probe is a real, non-empty value, and the listing does show this receipt.
    expect(array_filter($secrets, static fn (string $value): bool => strlen($value) >= 16))->toBe($secrets)
        ->and($receipt->intent_id)->toBe($headers[PaymentIntentHeaders::INTENT_ID]);

    $response = recentIntents($merchant);
    $response->assertStatus(200);

    expect($response->json('intents.*.payment_id'))->toBe([$receipt->payment_id]);

    $wire = $response->getContent().json_encode($response->headers->all(), JSON_THROW_ON_ERROR);

    foreach ($secrets as $name => $secret) {
        expect(str_contains($wire, $secret))->toBeFalse("The response reveals the {$name}.");
    }
});
