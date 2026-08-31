<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Tests\Support\CountingMerchantContext;
use Modules\Payments\Tests\Support\Initiation;

/*
|--------------------------------------------------------------------------
| GET /api/payments/{paymentId} - the authenticated read contract
|--------------------------------------------------------------------------
|
| REAL SANCTUM BEARER TOKENS, never Sanctum::actingAs, for the reason
| Modules/Payments/tests/Support/Initiation.php records: actingAs installs the user
| straight into the guard and would still pass against a misconfigured guard or a
| missing personal_access_tokens table.
|
| WHAT THIS FILE PROVES: status, exact body, field order, header presence and
| absence, and that missing, foreign and malformed ids are ONE answer at the wire.
| The transaction, connection and table-allowlist claims below HTTP belong to
| Modules/Payments/tests/Integration/PaymentQueryTest.php, where no RefreshDatabase
| wrapper turns a transaction into a savepoint.
|
| THERE IS NO REBUILDING TEST. 503 projection_rebuilding has no producer until
| T11's shared projection gate exists; asserting it here would require inventing
| one.
|
*/

function showPayment(string $paymentId, ?Merchant $merchant = null): TestResponse
{
    $token = Initiation::token($merchant);

    // Required for the same reason Initiation::send() gives: RequestGuard memoises
    // its resolved user across requests made by one test, so a second request would
    // silently keep the first token's merchant and every isolation claim here would
    // pass for the wrong reason.
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

it('refuses an unauthenticated read with the shared 401 contract', function (): void {
    $response = test()->getJson('/api/payments/'.Str::uuid7());

    $response->assertStatus(401);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('unauthenticated');
});

it('returns the owned projection as exactly five fields in wire order', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($merchant->getKey(), $paymentId);

    $response = showPayment($paymentId, $merchant);

    $response->assertStatus(200);

    // The WHOLE document, compared order-sensitively: an extra member, a renamed
    // one or a reordered one all fail here. merchant_id, source_event_id and
    // aggregate_version are internal and must never appear.
    expect($response->json())->toBe([
        'payment_id' => $paymentId,
        'amount_minor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'initiated_at' => '2026-08-21T10:11:12.123456+00:00',
    ]);

    // amount_minor crosses the wire as a NUMBER, not a quoted bigint.
    expect($response->json('amount_minor'))->toBeInt();

    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    // A projected payment is not pending, so nothing tells the client to come back.
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

it('answers payment_not_found for a payment neither table knows', function (): void {
    $response = showPayment((string) Str::uuid7());

    $response->assertStatus(404);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('payment_not_found')
        ->and($response->json('type'))->toBe('/problems/payment-not-found')
        ->and($response->json('title'))->toBe('Payment not found')
        ->and($response->json('status'))->toBe(404);
});

it('answers payment_not_found for another merchant projection and serves its owner', function (): void {
    $owner = Merchant::factory()->create();
    $stranger = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    showPaymentProjection($owner->getKey(), $paymentId);

    showPayment($paymentId, $stranger)->assertStatus(404);

    // The positive control makes the refusal non-vacuous: the projection is really
    // there, and its owner really can read it.
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

it('cannot be told apart from a missing payment when the payment is someone else', function (): void {
    $stranger = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();

    // Same id, same caller, one difference: whether another merchant's row exists.
    $missing = showPayment($paymentId, $stranger);

    showPaymentProjection(Merchant::factory()->create()->getKey(), $paymentId);

    $foreign = showPayment($paymentId, $stranger);

    // BYTES, not a decoded document: a difference in key order or in `instance`
    // would leak the distinction just as effectively as a different code.
    expect($foreign->getContent())->toBe($missing->getContent())
        ->and($foreign->getStatusCode())->toBe($missing->getStatusCode())
        ->and($foreign->headers->get('Content-Type'))->toBe($missing->headers->get('Content-Type'));
});

it('gives a malformed payment id the payment contract, never the generic one', function (string $malformed): void {
    $response = showPayment($malformed);

    $response->assertStatus(404);

    // not_found here would mean a route constraint had turned an invalid id into a
    // different error class from an unknown one.
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

it('leaves an unknown api path on the generic not_found contract', function (): void {
    // payment_not_found is reachable only through the query endpoint's own
    // exception type. A status-driven mapping would hand it to every unmatched path.
    $response = test()->getJson('/api/nothing-here');

    $response->assertStatus(404);

    expect($response->json('code'))->toBe('not_found')
        ->and($response->json('code'))->not->toBe('payment_not_found');
});
