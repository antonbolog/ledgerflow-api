<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\AtomicTransaction;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Tests\Support\Initiation;

afterEach(function (): void {
    // Str::$uuidFactory is static, so a frozen uuid would survive into later tests.
    Str::createUuidsNormally();
});

it('commits one receipt, one stored event and one outbox row', function (): void {
    $merchant = Merchant::factory()->create();

    $response = Initiation::send(merchant: $merchant);

    $response->assertStatus(202);

    $receipt = DB::table('payment_idempotency_receipts')->sole();

    expect(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(1)
        ->and(DB::table('payment_outbox')->count())->toBe(1)
        ->and($receipt->merchant_id)->toBe($merchant->getKey())
        ->and($receipt->idempotency_key_hash)->toBe(hash('sha256', Initiation::KEY))
        ->and((int) $receipt->response_status)->toBe(202)
        ->and($receipt->response_body)->toBe($response->getContent())
        ->and($receipt->response_location)->toBe($response->headers->get('Location'));
});

it('replays the exact stored bytes, status and location for an identical retry', function (): void {
    $merchant = Merchant::factory()->create();

    $first = Initiation::send(merchant: $merchant);
    $second = Initiation::send(merchant: $merchant);

    // getContent(), not json(): the contract is bytes, including key order.
    expect($second->getContent())->toBe($first->getContent())
        ->and($second->getStatusCode())->toBe($first->getStatusCode())
        ->and($second->headers->get('Location'))->toBe($first->headers->get('Location'));

    expect(DB::table('payment_idempotency_receipts')->count())->toBe(1)
        ->and(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(1)
        ->and(DB::table('payment_outbox')->count())->toBe(1);
});

it('returns 409 for the same key with a different payload and writes nothing new', function (): void {
    $merchant = Merchant::factory()->create();

    Initiation::send(['amount_minor' => 1_250], merchant: $merchant);
    $conflict = Initiation::send(['amount_minor' => 999], merchant: $merchant);

    $conflict->assertStatus(409);

    expect($conflict->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($conflict->json('code'))->toBe('idempotency_payload_mismatch')
        ->and(DB::table('payment_idempotency_receipts')->count())->toBe(1)
        ->and(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(1)
        ->and(DB::table('payment_outbox')->count())->toBe(1);
});

it('treats a case-different currency as the same canonical payload', function (): void {
    $merchant = Merchant::factory()->create();

    $first = Initiation::send(['currency' => 'USD'], merchant: $merchant);
    $second = Initiation::send(['currency' => 'usd'], merchant: $merchant);

    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('payment_idempotency_receipts')->count())->toBe(1);
});

it('scopes the key by merchant so two merchants never collide', function (): void {
    $first = Initiation::send(merchant: Merchant::factory()->create());
    $second = Initiation::send(merchant: Merchant::factory()->create());

    expect($second->json('payment_id'))->not->toBe($first->json('payment_id'))
        ->and(DB::table('payment_idempotency_receipts')->count())->toBe(2)
        ->and(DB::table('payment_outbox')->count())->toBe(2);
});

it('never lets one merchant replay another merchant receipt', function (): void {
    Initiation::send(['amount_minor' => 111], merchant: Merchant::factory()->create());

    Initiation::send(['amount_minor' => 222], merchant: Merchant::factory()->create())
        ->assertStatus(202);
});

it('reserves against the merchant/key constraint only', function (): void {
    $collidingPaymentId = '01984f6f-7777-7000-8000-000000000001';
    $squatter = Merchant::factory()->create();

    DB::table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $squatter->getKey(),
        'idempotency_key_hash' => str_repeat('9', 64),
        'request_fingerprint' => str_repeat('8', 64),
        'payment_id' => $collidingPaymentId,
        'response_status' => 202,
        'response_body' => '{}',
        'response_location' => '/api/payments/'.$collidingPaymentId,
        'created_at' => CarbonImmutable::now(),
        'updated_at' => CarbonImmutable::now(),
    ]);

    Str::createUuidsUsing(fn (): string => $collidingPaymentId);

    $response = Initiation::send(merchant: Merchant::factory()->create());

    expect($response->getStatusCode())->toBe(500)
        ->and($response->json('code'))->toBe('internal_error')
        ->and($response->json('code'))->not->toBe('idempotency_contention')
        ->and($response->headers->get('Retry-After'))->toBeNull()
        ->and(DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->count())->toBe(0)
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});

it('stores the canonicalised six-field payload under the alias', function (): void {
    $merchant = Merchant::factory()->create();

    $response = Initiation::send(['currency' => 'usd', 'amount_minor' => 4_242], merchant: $merchant);

    $stored = DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->sole();
    $payload = json_decode((string) $stored->event_properties, true, flags: JSON_THROW_ON_ERROR);

    expect($stored->aggregate_uuid)->toBe($response->json('payment_id'))
        ->and((int) $stored->aggregate_version)->toBe(1)
        ->and((int) $stored->event_version)->toBe(1)
        ->and(array_keys($payload))->toEqualCanonicalizing([
            'eventId', 'paymentId', 'merchantId', 'amountMinor', 'currency', 'occurredAt',
        ])
        ->and($payload['merchantId'])->toBe($merchant->getKey())
        ->and($payload['amountMinor'])->toBe(4_242)
        ->and($payload['currency'])->toBe('USD');
});

it('derives every outbox column from the row the repository just wrote', function (): void {
    Initiation::send(merchant: Merchant::factory()->create());

    $stored = DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->sole();
    $row = DB::table('payment_outbox')->sole();
    $payload = json_decode((string) $stored->event_properties, true, flags: JSON_THROW_ON_ERROR);

    expect($row->event_id)->toBe($payload['eventId'])
        ->and($row->event_type)->toBe($stored->event_class)
        ->and($row->event_type)->toBe(PaymentInitiated::ALIAS)
        ->and($row->aggregate_uuid)->toBe($stored->aggregate_uuid)
        ->and((int) $row->aggregate_version)->toBe((int) $stored->aggregate_version)
        ->and((int) $row->schema_version)->toBe(1);

    expect(DB::table('payment_outbox')
        ->whereRaw('payload = ?::jsonb', [$stored->event_properties])->count())->toBe(1);

    expect(CarbonImmutable::parse($row->occurred_at)->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT))
        ->toBe($payload['occurredAt']);
});

it('leaves every T7 and T8 delivery column at its default', function (): void {
    Initiation::send(merchant: Merchant::factory()->create());

    $row = DB::table('payment_outbox')->sole();

    expect((int) $row->publish_count)->toBe(0)
        ->and((int) $row->consecutive_transport_failures)->toBe(0)
        ->and((int) $row->projection_failure_count)->toBe(0)
        ->and($row->last_published_at)->toBeNull()
        ->and($row->projection_completed_at)->toBeNull()
        ->and($row->exhausted_at)->toBeNull()
        ->and($row->last_transport_error)->toBeNull()
        ->and($row->last_projection_error)->toBeNull()
        ->and($row->available_at)->not->toBeNull();
});

it('enqueues nothing while writing the envelope', function (): void {
    Queue::fake();

    Initiation::send(merchant: Merchant::factory()->create());

    Queue::assertNothingPushed();
});

it('answers exhausted contention with 503, Retry-After: 1 and problem details', function (): void {
    $this->instance(AtomicTransaction::class, new class implements AtomicTransaction
    {
        public function run(Closure $work): mixed
        {
            throw IdempotencyContention::afterAttempts(3, '55P03');
        }
    });

    $response = Initiation::send(merchant: Merchant::factory()->create());

    $response->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->json('code'))->toBe('idempotency_contention')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('detail'))->not->toContain('lock')
        ->and($response->json('detail'))->not->toContain('55P03')
        ->and($response->json('detail'))->not->toContain('payment_idempotency_receipts');
});

it('writes the raw idempotency key to no durable column, exception or log line', function (): void {
    $captured = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$captured): void {
        $captured[] = $message->message.' '.json_encode($message->context, JSON_THROW_ON_ERROR);
    });

    $merchant = Merchant::factory()->create();
    $raw = 'raw-key-'.bin2hex(random_bytes(16));

    Initiation::send(['amount_minor' => 1], ['Idempotency-Key' => $raw], $merchant)->assertStatus(202);
    $conflict = Initiation::send(['amount_minor' => 2], ['Idempotency-Key' => $raw], $merchant);
    $invalid = Initiation::send(['amount_minor' => 0], ['Idempotency-Key' => $raw], $merchant);

    $conflict->assertStatus(409);
    $invalid->assertStatus(422);

    foreach (['payment_idempotency_receipts', 'stored_events', 'payment_outbox'] as $table) {
        foreach (DB::table($table)->get() as $row) {
            expect(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($raw);
        }
    }

    foreach ($captured as $line) {
        expect($line)->not->toContain($raw);
    }

    expect($conflict->getContent())->not->toContain($raw)
        ->and($invalid->getContent())->not->toContain($raw);

    expect($captured)->not->toBeEmpty();
});

it('answers 503 projection_rebuilding with Retry-After: 1 while the gate is closed, and writes nothing', function (): void {
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);

    $merchant = Merchant::factory()->create();
    $response = Initiation::send(merchant: $merchant);

    $response->assertStatus(503);

    expect($response->json('code'))->toBe('projection_rebuilding')
        ->and($response->json('detail'))->toBe('A projection rebuild is in progress. Retry.')
        ->and($response->headers->get('Retry-After'))->toBe('1')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');

    expect(DB::table('payment_idempotency_receipts')->count())->toBe(0)
        ->and(DB::table('stored_events')->count())->toBe(0)
        ->and(DB::table('payment_outbox')->count())->toBe(0);

    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);

    Initiation::send(merchant: $merchant)->assertStatus(202);
});
