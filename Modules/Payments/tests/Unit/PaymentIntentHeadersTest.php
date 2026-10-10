<?php

declare(strict_types=1);

use Modules\Payments\Http\Requests\PaymentIntentHeaders;

const SLICE_INTENT_ID = '0199c6a8-3f1e-7c2a-9b4d-5e6f7a8b9c0d';

it('accepts exactly one canonical lowercase UUID as the intent id', function (): void {
    expect(PaymentIntentHeaders::intentId([SLICE_INTENT_ID]))->toBe(SLICE_INTENT_ID);
});

it('refuses an intent id that is :dataset', function (array $values): void {
    expect(PaymentIntentHeaders::intentId($values))->toBeNull();
})->with([
    'missing' => [[]],
    'empty' => [['']],
    'null' => [[null]],
    'uppercase' => [[strtoupper(SLICE_INTENT_ID)]],
    'braced' => [['{'.SLICE_INTENT_ID.'}']],
    'surrounded by whitespace' => [[' '.SLICE_INTENT_ID.' ']],
    'followed by a newline' => [[SLICE_INTENT_ID."\n"]],
    'not a UUID' => [['not-a-uuid']],
    'missing its hyphens' => [[str_replace('-', '', SLICE_INTENT_ID)]],
    'sent twice' => [[SLICE_INTENT_ID, SLICE_INTENT_ID]],
    'two comma-joined values' => [[SLICE_INTENT_ID.', '.SLICE_INTENT_ID]],
]);

it('accepts the expiry exactly as Date.prototype.toISOString() writes it, in UTC', function (): void {
    $expiresAt = PaymentIntentHeaders::expiresAt(['2026-10-08T12:15:00.123Z']);

    expect($expiresAt)->not->toBeNull()
        ->and($expiresAt?->getTimezone()->getName())->toBe('UTC')
        ->and($expiresAt?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-10-08T12:15:00.123000+00:00');
});

it('refuses an expiry that is :dataset', function (array $values): void {
    expect(PaymentIntentHeaders::expiresAt($values))->toBeNull();
})->with([
    'missing' => [[]],
    'empty' => [['']],
    'null' => [[null]],
    'without milliseconds' => [['2026-10-08T12:15:00Z']],
    'with microseconds' => [['2026-10-08T12:15:00.123456Z']],
    'with an offset instead of Z' => [['2026-10-08T12:15:00.000+00:00']],
    'with a lowercase z' => [['2026-10-08T12:15:00.000z']],
    'with a space separator' => [['2026-10-08 12:15:00.000Z']],
    'an extended year' => [['+012026-10-08T12:15:00.000Z']],
    'an impossible date' => [['2026-02-30T12:15:00.000Z']],
    'in year zero, which PostgreSQL rejects' => [['0000-01-01T00:00:00.000Z']],
    'an impossible hour' => [['2026-10-08T24:00:00.000Z']],
    'surrounded by whitespace' => [[' 2026-10-08T12:15:00.000Z ']],
    'followed by a newline' => [["2026-10-08T12:15:00.000Z\n"]],
    'a Unix timestamp' => [['1791547200']],
    'sent twice' => [['2026-10-08T12:15:00.000Z', '2026-10-08T12:15:00.000Z']],
]);
