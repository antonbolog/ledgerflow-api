<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Initiation\IdempotencyPayloadMismatch;
use Modules\Payments\Application\Initiation\IdempotencyReceipts;
use Modules\Payments\Application\Initiation\InitiatePayment;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Application\Initiation\ReservationRaced;
use Modules\Payments\Infrastructure\Persistence\DatabaseIdempotencyReceipts;
use Modules\Payments\Infrastructure\Persistence\DatabasePaymentIntentDeadline;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Integration\Initiations;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\RacingReceipts;

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
});

function arbitrationRacing(): RacingReceipts
{
    $racing = new RacingReceipts(new DatabaseIdempotencyReceipts(DB::connection()));

    app()->instance(IdempotencyReceipts::class, $racing);

    return $racing;
}

/** Commits a competing request's receipt on its own connection, as a concurrent request would have. */
function arbitrationCommitCompetitor(PaymentInitiationContext $competitor): void
{
    DB::connection(IntegrationDatabase::LOCK)->table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $competitor->merchantId,
        'idempotency_key_hash' => $competitor->idempotencyKeyHash,
        'request_fingerprint' => $competitor->requestFingerprint,
        'payment_id' => $competitor->paymentId,
        'response_status' => $competitor->responseStatus,
        'response_body' => $competitor->responseBody,
        'response_location' => $competitor->responseLocation,
        'intent_id' => $competitor->intentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array{int, int, int} */
function arbitrationObservedRows(): array
{
    return [
        IntegrationDatabase::observedCount('payment_idempotency_receipts'),
        IntegrationDatabase::observedCount('stored_events'),
        IntegrationDatabase::observedCount('payment_outbox'),
    ];
}

function arbitrationInitiate(PaymentInitiationContext $context): mixed
{
    try {
        return resolve(InitiatePayment::class)($context);
    } catch (Throwable $exception) {
        return $exception;
    }
}

it('converges a concurrent submit of the same intent :dataset on one complete initiation', function (string $winnerKey): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = (string) Str::uuid();
    $loserContext = Initiations::context($merchant, key: 'loser', intentId: $intent);
    $winnerContext = Initiations::context($merchant, key: $winnerKey, intentId: $intent);

    // The loser runs the production graph on its own connection; the winner runs the full production initiation on the
    // default connection between the loser's lookup and its arbiter insert, so both pass the lookup with nothing found.
    $lock = DB::connection(IntegrationDatabase::LOCK);
    $racing = new RacingReceipts(new DatabaseIdempotencyReceipts($lock));
    $loser = new InitiatePayment(
        new RetryingAtomicTransaction($lock, new ProjectionGate($lock)),
        $racing,
        new DatabasePaymentIntentDeadline($lock),
    );

    $winner = null;
    $racing->afterFirstLookup = function () use (&$winner, $winnerContext): void {
        $winner = resolve(InitiatePayment::class)($winnerContext);
    };

    $outcome = $loser($loserContext);

    $stored = DB::connection(IntegrationDatabase::OBSERVER)->table('payment_idempotency_receipts')->sole();

    // One atomic initiation: the winner's receipt, stored event and envelope, all from one transaction.
    expect(arbitrationObservedRows())->toBe([1, 1, 1])
        ->and($stored->payment_id)->toBe($winnerContext->paymentId)
        ->and(IntegrationDatabase::observedXmin('stored_events'))->toBe(IntegrationDatabase::observedXmin('payment_idempotency_receipts'))
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe(IntegrationDatabase::observedXmin('payment_idempotency_receipts'));

    // The loser met the committed intent at the arbiter, wrote nothing, and replays the winner's exact response.
    expect($winner?->paymentId)->toBe($winnerContext->paymentId)
        ->and($outcome->paymentId)->toBe($winnerContext->paymentId)
        ->and($outcome->responseBody)->toBe($winnerContext->responseBody)
        ->and($outcome->responseStatus)->toBe($winnerContext->responseStatus)
        ->and($outcome->responseLocation)->toBe($winnerContext->responseLocation)
        ->and($racing->reservations)->toBe(1)
        ->and($racing->lookups)->toBe(2)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)->table('stored_events')->where('aggregate_uuid', $loserContext->paymentId)->count())->toBe(0)
        ->and($lock->transactionLevel())->toBe(0);
})->with([
    'under the same key' => ['loser'],
    'under a different key' => ['winner'],
]);

it('refuses a concurrent crossed binding with 409 after the arbiter meets the committed intent', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $first = (string) Str::uuid();
    $context = Initiations::context($merchant, key: 'k2', intentId: $first);

    $racing = arbitrationRacing();
    $racing->afterFirstLookup = function () use ($merchant, $first): void {
        arbitrationCommitCompetitor(Initiations::context($merchant, key: 'k1', intentId: $first));
        arbitrationCommitCompetitor(Initiations::context($merchant, key: 'k2', intentId: (string) Str::uuid()));
    };

    expect(arbitrationInitiate($context))->toBeInstanceOf(IdempotencyPayloadMismatch::class)
        ->and(arbitrationObservedRows())->toBe([2, 0, 0]);
});

it('reruns once after a reservation race and refuses the key bound concurrently to another intent', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = Initiations::context($merchant, key: 'contested', intentId: (string) Str::uuid());

    $racing = arbitrationRacing();
    $racing->afterFirstLookup = fn () => arbitrationCommitCompetitor(
        Initiations::context($merchant, key: 'contested', intentId: (string) Str::uuid()),
    );

    // The key collision raises 23505 inside the arbiter insert; only the rerun's lookup can see the committed key.
    expect(arbitrationInitiate($context))->toBeInstanceOf(IdempotencyPayloadMismatch::class)
        ->and($racing->reservations)->toBe(1)
        ->and($racing->lookups)->toBe(2)
        ->and(arbitrationObservedRows())->toBe([1, 0, 0]);
});

it('refuses a sequential crossed binding with 409', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();

    resolve(InitiatePayment::class)(Initiations::context($merchant, key: 'k1', intentId: $first));
    resolve(InitiatePayment::class)(Initiations::context($merchant, key: 'k2', intentId: $second));

    expect(arbitrationInitiate(Initiations::context($merchant, key: 'k2', intentId: $first)))
        ->toBeInstanceOf(IdempotencyPayloadMismatch::class)
        ->and(arbitrationObservedRows())->toBe([2, 2, 2]);
});

it('surfaces a payment id collision that survives the rerun as an internal error and writes nothing', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    arbitrationCommitCompetitor(Initiations::context($merchant, key: 'owner', intentId: (string) Str::uuid(), paymentId: $paymentId));

    $racing = arbitrationRacing();

    expect(arbitrationInitiate(Initiations::context($merchant, key: 'collider', intentId: (string) Str::uuid(), paymentId: $paymentId)))
        ->toBeInstanceOf(ReservationRaced::class)
        ->and($racing->reservations)->toBe(2)
        ->and(arbitrationObservedRows())->toBe([1, 0, 0]);
});

it('replays an already committed intent after its expiry has passed', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $intent = (string) Str::uuid();
    $expiresAt = (string) DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        "select to_char((clock_timestamp() + interval '1500 milliseconds') at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.MS\"Z\"') as e",
    )->e;

    $first = resolve(InitiatePayment::class)(Initiations::context($merchant, key: 'expiring', intentId: $intent, intentExpiresAt: $expiresAt));

    $giveUpAt = hrtime(true) + 5_000_000_000;

    while ((int) DB::connection(IntegrationDatabase::OBSERVER)
        ->selectOne('select (clock_timestamp() > ?::timestamptz)::int as past', [$expiresAt])->past !== 1) {
        expect(hrtime(true))->toBeLessThan($giveUpAt);
        Sleep::usleep(20_000);
    }

    $replay = resolve(InitiatePayment::class)(Initiations::context($merchant, key: 'expiring', intentId: $intent, intentExpiresAt: $expiresAt));

    expect($replay->paymentId)->toBe($first->paymentId)
        ->and($replay->responseBody)->toBe($first->responseBody)
        ->and($replay->responseLocation)->toBe($first->responseLocation)
        ->and(arbitrationObservedRows())->toBe([1, 1, 1]);
});

it('records the intent on the receipt it commits', function (): void {
    $merchant = Merchant::factory()->create()->getKey();
    $context = Initiations::context($merchant, intentId: (string) Str::uuid());

    resolve(InitiatePayment::class)($context);

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_idempotency_receipts')->value('intent_id'))
        ->toBe($context->intentId);
});
