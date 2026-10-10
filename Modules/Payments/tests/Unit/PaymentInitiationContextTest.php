<?php

declare(strict_types=1);

use Modules\Payments\Application\Initiation\PaymentInitiationContext;

function slice2Context(
    int $merchantId = 7,
    string $paymentId = '01984f6f-6a20-7000-8000-000000000001',
    int $amountMinor = 1_250,
    string $currency = 'USD',
): PaymentInitiationContext {
    return new PaymentInitiationContext(
        merchantId: $merchantId,
        paymentId: $paymentId,
        eventId: '01984f6f-6a20-7000-8000-000000000002',
        amountMinor: $amountMinor,
        currency: $currency,
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
        idempotencyKeyHash: str_repeat('a', 64),
        requestFingerprint: PaymentInitiationContext::fingerprint($merchantId, $amountMinor, $currency),
        intentId: '0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d',
        intentExpiresAt: '2026-08-14T10:11:42.123Z',
    );
}

it('serialises the success body exactly once and exposes the bytes', function (): void {
    $context = slice2Context();

    expect($context->responseBody)
        ->toBe('{"payment_id":"01984f6f-6a20-7000-8000-000000000001","status":"initiated"}')
        ->and($context->responseLocation)->toBe('/api/payments/01984f6f-6a20-7000-8000-000000000001')
        ->and($context->responseStatus)->toBe(202);
});

it('derives a stable fingerprint from canonical values only', function (): void {
    expect(PaymentInitiationContext::fingerprint(7, 1_250, 'USD'))
        ->toBe(PaymentInitiationContext::fingerprint(7, 1_250, 'USD'))
        ->and(strlen(PaymentInitiationContext::fingerprint(7, 1_250, 'USD')))->toBe(64)
        ->and(PaymentInitiationContext::fingerprint(7, 1_250, 'USD'))
        ->not->toBe(PaymentInitiationContext::fingerprint(7, 1_251, 'USD'))
        ->and(PaymentInitiationContext::fingerprint(7, 1_250, 'USD'))
        ->not->toBe(PaymentInitiationContext::fingerprint(8, 1_250, 'USD'));
});

it('never carries the raw idempotency key on any property', function (): void {
    $raw = 'raw-key-that-must-not-survive';

    $context = new PaymentInitiationContext(
        merchantId: 7,
        paymentId: '01984f6f-6a20-7000-8000-000000000001',
        eventId: '01984f6f-6a20-7000-8000-000000000002',
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
        idempotencyKeyHash: PaymentInitiationContext::hashKey($raw),
        requestFingerprint: PaymentInitiationContext::fingerprint(7, 1_250, 'USD'),
        intentId: '0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d',
        intentExpiresAt: '2026-08-14T10:11:42.123Z',
    );

    expect(PaymentInitiationContext::hashKey($raw))->toBe(hash('sha256', $raw));

    foreach (new ReflectionClass($context)->getProperties() as $property) {
        expect((string) json_encode($property->getValue($context)))->not->toContain($raw);
    }
});
