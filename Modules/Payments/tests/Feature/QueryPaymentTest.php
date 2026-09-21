<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Query\PaymentNotFound;
use Modules\Payments\Tests\Support\CountingMerchantContext;
use Modules\Payments\Tests\Support\Initiation;

function showPayment(string $paymentId, ?Merchant $merchant = null): TestResponse
{
    $token = Initiation::token($merchant);

    // The token guard memoises its user across requests in one test.
    Auth::forgetGuards();

    return test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/payments/'.$paymentId);
}

function showPaymentProjection(int $merchantId, string $paymentId): void
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

function showPaymentReceipt(int $merchantId, string $paymentId): void
{
    DB::table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'idempotency_key_hash' => hash('sha256', $paymentId),
        'request_fingerprint' => str_repeat('b', 64),
        'payment_id' => $paymentId,
        'response_status' => 202,
        'response_body' => '{"payment_id":"'.$paymentId.'","status":"initiated"}',
        'response_location' => '/api/payments/'.$paymentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('returns the owned projection as exactly five fields in wire order', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($merchant->getKey(), $paymentId);

    $response = showPayment($paymentId, $merchant);

    $response->assertStatus(200);

    expect($response->json())->toBe([
        'payment_id' => $paymentId,
        'amount_minor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'initiated_at' => '2026-08-21T10:11:12.123456+00:00',
    ]);

    expect($response->json('amount_minor'))->toBeInt();

    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    $response->assertHeaderMissing('Retry-After');
});

it('returns the pending contract with Retry-After when only the receipt exists', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentReceipt($merchant->getKey(), $paymentId);

    $response = showPayment($paymentId, $merchant);

    $response->assertStatus(202);

    expect($response->json())->toBe([
        'payment_id' => $paymentId,
        'status' => 'projection_pending',
    ]);

    expect($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/json');
});

it('answers payment_not_found for another merchant projection and serves its owner', function (): void {
    $owner = Merchant::factory()->create();
    $stranger = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($owner->getKey(), $paymentId);

    showPayment($paymentId, $stranger)->assertStatus(404);

    showPayment($paymentId, $owner)->assertStatus(200);
});

it('answers payment_not_found for another merchant pending receipt and serves its owner', function (): void {
    $owner = Merchant::factory()->create();
    $stranger = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentReceipt($owner->getKey(), $paymentId);

    showPayment($paymentId, $stranger)->assertStatus(404);
    showPayment($paymentId, $owner)->assertStatus(202);
});

it('cannot be told apart from a missing payment when the payment is someone else', function (callable $giveItToAStranger): void {
    $stranger = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    $missing = showPayment($paymentId, $stranger);

    $giveItToAStranger(Merchant::factory()->create()->getKey(), $paymentId);

    $foreign = showPayment($paymentId, $stranger);

    // Bytes: a key order or instance difference would leak the distinction too.
    expect($foreign->getContent())->toBe($missing->getContent())
        ->and($foreign->getStatusCode())->toBe($missing->getStatusCode())
        ->and($foreign->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'));
})->with([
    'foreign projection' => [fn (): callable => showPaymentProjection(...)],
    'foreign pending receipt' => [fn (): callable => showPaymentReceipt(...)],
]);

it('gives a malformed payment id the payment contract, never the generic one', function (string $malformed): void {
    $response = showPayment($malformed);

    $response->assertStatus(404);

    expect($response->json('code'))->toBe('payment_not_found')
        ->and($response->json('instance'))->toBe('/api/payments/'.$malformed);
})->with([
    'plain text' => ['not-a-uuid'],
    'truncated uuid' => ['0199a0f0-1111-7000-8000-00000000'],
]);

it('resolves the merchant through MerchantContext exactly once', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($merchant->getKey(), $paymentId);

    $context = new CountingMerchantContext($merchant->getKey());
    app()->instance(MerchantContext::class, $context);

    showPayment($paymentId, $merchant)->assertStatus(200);

    expect($context->calls)->toBe(1);
});

it('does not report a not-found payment to the log', function (): void {
    $handler = resolve(ExceptionHandler::class);

    expect($handler->shouldReport(PaymentNotFound::forRequestedPayment()))->toBeFalse();

    expect($handler->shouldReport(new RuntimeException('unrelated')))->toBeTrue();
});

it('leaves an unknown api path on the generic not_found contract', function (): void {
    $response = test()->getJson('/api/nothing-here');

    $response->assertStatus(404);

    expect($response->json('code'))->toBe('not_found');
});

it('answers 503 projection_rebuilding with Retry-After: 1 while the gate is closed', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($merchant->getKey(), $paymentId);

    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);

    $response = showPayment($paymentId, $merchant);

    $response->assertStatus(503);

    expect($response->json())->toBe([
        'type' => '/problems/projection-rebuilding',
        'title' => 'Projection rebuilding',
        'status' => 503,
        'detail' => 'A projection rebuild is in progress. Retry.',
        'instance' => '/api/payments/'.$paymentId,
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

    showPayment($paymentId, $merchant)->assertStatus(200);
});
