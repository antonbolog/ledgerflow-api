<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\ReceiptCandidate;
use Modules\Payments\Application\Initiation\ReservationRaced;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Tests\Integration\Initiations;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

afterEach(function (): void {
    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
    CarbonImmutable::setTestNow();
});

function intentReceipts(): IdempotencyReceipts
{
    return resolve(IdempotencyReceipts::class);
}

function intentId(): string
{
    return (string) Str::uuid();
}

/** @return list<?string> intent ids of the candidates, in returned order */
function intentCandidateIntents(int $merchantId, string $intentId, string $key): array
{
    return array_map(
        fn (ReceiptCandidate $candidate): ?string => $candidate->intentId,
        intentReceipts()->findArbitrationCandidates($merchantId, $intentId, PaymentInitiationContext::hashKey($key)),
    );
}

function intentReserve(PaymentInitiationContext $context): bool
{
    return intentReceipts()->reserveIntent($context);
}

function intentHistorical(int $merchantId, string $key): void
{
    DB::table('payment_idempotency_receipts')->insert(
        ['id' => (string) Str::uuid7()] + intentReceiptRow(Initiations::context($merchantId, key: $key)),
    );
}

it('finds no candidate when neither the intent nor the key has a receipt', function (): void {
    $merchant = Merchant::factory()->create()->getKey();

    expect(intentCandidateIntents($merchant, intentId(), 'unused-key'))->toBe([]);
});

it('finds one candidate by intent, by key, or by both on the same receipt', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = intentId();
    $other = intentId();

    expect(intentReserve(Initiations::context($merchant, key: 'key-a', intentId: $intent)))->toBeTrue();

    $byIntent = intentReceipts()->findArbitrationCandidates($merchant, $intent, PaymentInitiationContext::hashKey('key-b'));

    expect($byIntent)->toHaveCount(1)
        ->and($byIntent[0]->intentId)->toBe($intent)
        ->and($byIntent[0]->receipt->requestFingerprint)->toBe(PaymentInitiationContext::fingerprint($merchant, 1_250, 'USD'))
        ->and(intentCandidateIntents($merchant, $other, 'key-a'))->toBe([$intent])
        ->and(intentCandidateIntents($merchant, $intent, 'key-a'))->toBe([$intent]);
});

it('finds two distinct candidates when the intent and the key are bound to different receipts', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $first = intentId();
    $second = intentId();

    intentReserve(Initiations::context($merchant, key: 'key-1', intentId: $first));
    intentReserve(Initiations::context($merchant, key: 'key-2', intentId: $second));

    expect(intentCandidateIntents($merchant, $first, 'key-2'))->toEqualCanonicalizing([$first, $second]);
});

it('reports a historical receipt without an intent as a key candidate with a null intent', function (): void {
    $merchant = Merchant::factory()->create()->getKey();

    intentHistorical($merchant, 'historical');

    expect(intentCandidateIntents($merchant, intentId(), 'historical'))->toBe([null]);
});

it('never returns another merchant\'s receipt for the same intent or key', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $stranger = Merchant::factory()->create()->getKey();
    $intent = intentId();

    intentReserve(Initiations::context($owner, key: 'shared-key', intentId: $intent));

    expect(intentCandidateIntents($stranger, $intent, 'shared-key'))->toBe([]);
});

it('lets two merchants use one intent id independently', function (): void {
    $intent = intentId();

    expect(intentReserve(Initiations::context(Merchant::factory()->create()->getKey(), key: 'k', intentId: $intent)))->toBeTrue()
        ->and(intentReserve(Initiations::context(Merchant::factory()->create()->getKey(), key: 'k', intentId: $intent)))->toBeTrue()
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(2);
});

it('reserves a new intent and records it on the receipt', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = intentId();
    $context = Initiations::context($merchant, intentId: $intent);

    expect(intentReserve($context))->toBeTrue();

    $row = DB::connection(IntegrationDatabase::OBSERVER)->table('payment_idempotency_receipts')->sole();

    expect($row->intent_id)->toBe($intent)
        ->and($row->payment_id)->toBe($context->paymentId)
        ->and($row->idempotency_key_hash)->toBe($context->idempotencyKeyHash)
        ->and($row->response_body)->toBe($context->responseBody);
});

it('does nothing, without raising, when the intent is already committed', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = intentId();

    intentReserve(Initiations::context($merchant, key: 'first', intentId: $intent));

    expect(intentReserve(Initiations::context($merchant, key: 'second', intentId: $intent)))->toBeFalse()
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1);
});

it('waits behind an in-flight insert of the same intent, then yields to it once committed', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = intentId();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->table('payment_idempotency_receipts')->insert(
        ['id' => (string) Str::uuid7(), 'intent_id' => $intent] + intentReceiptRow(Initiations::context($merchant, key: 'in-flight')),
    );

    // A bounded wait proves the arbiter blocks on the uncommitted row instead of inserting a duplicate beside it.
    $waited = null;

    try {
        DB::transaction(function () use ($merchant, $intent): void {
            DB::statement("set local lock_timeout = '250ms'");
            intentReserve(Initiations::context($merchant, key: 'contender', intentId: $intent));
        });
    } catch (QueryException $exception) {
        $waited = RetryingAtomicTransaction::sqlStateOf($exception);
    }

    $lock->commit();

    expect($waited)->toBe(IntegrationDatabase::LOCK_TIMEOUT_SQLSTATE)
        ->and(intentReserve(Initiations::context($merchant, key: 'contender', intentId: $intent)))->toBeFalse()
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1);
});

it('wins the intent once an in-flight insert of it rolls back', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = intentId();

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->table('payment_idempotency_receipts')->insert(
        ['id' => (string) Str::uuid7(), 'intent_id' => $intent] + intentReceiptRow(Initiations::context($merchant, key: 'abandoned')),
    );
    $lock->rollBack();

    expect(intentReserve(Initiations::context($merchant, key: 'successor', intentId: $intent)))->toBeTrue()
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1);
});

it('signals a reservation race when :dataset already holds another unique key', function (Closure $collide): void {
    $merchant = Merchant::factory()->create()->getKey();

    intentReserve(Initiations::context($merchant, key: 'holder', intentId: intentId()));

    $thrown = null;

    try {
        intentReserve($collide($merchant));
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(ReservationRaced::class)
        ->and($thrown?->getMessage())->not->toContain(PaymentInitiationContext::hashKey('holder'))
        ->and(IntegrationDatabase::observedCount('payment_idempotency_receipts'))->toBe(1);
})->with([
    'the same merchant and key' => [fn (int $merchant): PaymentInitiationContext => Initiations::context($merchant, key: 'holder')],
    'the same payment id' => [fn (int $merchant): PaymentInitiationContext => Initiations::context(
        $merchant,
        key: 'fresh',
        paymentId: (string) DB::table('payment_idempotency_receipts')->value('payment_id'),
    )],
]);

it('stamps the receipt with PostgreSQL time rather than the PHP clock', function (): void {
    $merchant = Merchant::factory()->create()->getKey();

    // A PHP clock decades off makes any PHP-side timestamp unmistakable.
    CarbonImmutable::setTestNow('2001-01-01 00:00:00');

    intentReserve(Initiations::context($merchant, key: 'intent', intentId: intentId()));

    $rows = DB::connection(IntegrationDatabase::OBSERVER)->select(
        'select (abs(extract(epoch from (clock_timestamp() - created_at))) < 60)::int as recent,
                (created_at = updated_at)::int as same
           from payment_idempotency_receipts',
    );

    expect($rows)->toHaveCount(1);

    foreach ($rows as $row) {
        expect((int) $row->recent)->toBe(1)
            ->and((int) $row->same)->toBe(1);
    }
});

/** @return array<string, mixed> */
function intentReceiptRow(PaymentInitiationContext $context): array
{
    return [
        'merchant_id' => $context->merchantId,
        'idempotency_key_hash' => $context->idempotencyKeyHash,
        'request_fingerprint' => $context->requestFingerprint,
        'payment_id' => $context->paymentId,
        'response_status' => $context->responseStatus,
        'response_body' => $context->responseBody,
        'response_location' => $context->responseLocation,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}
