<?php

declare(strict_types=1);

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Query\PaymentNotFound;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Query\PendingPayment;
use Modules\Payments\Application\Query\ProjectedPayment;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

/*
|--------------------------------------------------------------------------
| The owned payment query - two indexed lookups, one transaction
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE, NOT FEATURE, and for this adapter the reason is
| sharper than convenience: RefreshDatabase wraps every Feature test in a
| transaction, so transaction() here would open a SAVEPOINT at level 2 and fire
| no TransactionBeginning at depth 1. "Both lookups ran inside ONE transaction the
| adapter opened" would then pass against an adapter that opened none.
|
| THE NEGATIVE CLAIMS ARE READ FROM THE SQL, not from the source. Every test that
| says "only these tables" or "no writes" asserts over statements captured with
| DB::listen, so an adapter that grew a third read or an UPDATE fails here rather
| than at review.
|
| NO HTTP, NO ROUTE, NO CONTROLLER. This slice ends at the Application port; the
| authenticated endpoint, its serialization and its problem mapping are Slice 2's.
|
*/

function paymentQueryAdapter(): PaymentQuery
{
    return resolve(PaymentQuery::class);
}

/** One committed read row. Business fields are fixed so the exact contract is assertable. */
function paymentQueryProjection(
    int $merchantId,
    string $paymentId,
    int $amountMinor = 1_250,
    string $currency = 'USD',
    string $status = 'initiated',
    string $initiatedAt = '2026-08-21T10:11:12.123456+00:00',
): void {
    DB::table('payment_details')->insert([
        'payment_id' => $paymentId,
        'source_event_id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'status' => $status,
        'aggregate_version' => 1,
        'initiated_at' => $initiatedAt,
    ]);
}

/** One committed idempotency receipt - the proof a payment exists before it is projected. */
function paymentQueryReceipt(int $merchantId, string $paymentId): void
{
    DB::table('payment_idempotency_receipts')->insert([
        'id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'idempotency_key_hash' => str_repeat('a', 64),
        'request_fingerprint' => str_repeat('b', 64),
        'payment_id' => $paymentId,
        'response_status' => 202,
        'response_body' => '{"payment_id":"'.$paymentId.'","status":"initiated"}',
        'response_location' => '/api/payments/'.$paymentId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Every statement the default connection executes from here on, with the
 * transaction depth it ran at.
 *
 * The depth is captured AT EXECUTION TIME rather than asserted afterwards, because
 * afterwards is exactly when it is 0 again on a correct implementation and on a
 * broken one alike.
 */
function paymentQueryStatements(): ArrayObject
{
    $statements = new ArrayObject;

    DB::listen(function ($query) use ($statements): void {
        $statements[] = [
            'sql' => $query->sql,
            'connection' => $query->connectionName,
            'level' => DB::connection()->transactionLevel(),
        ];
    });

    return $statements;
}

/** @return list<string> */
function paymentQuerySql(ArrayObject $statements): array
{
    return array_column(iterator_to_array($statements), 'sql');
}

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

it('returns the owned projection with exactly the projected fields', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId);

    $found = paymentQueryAdapter()->findOwned($merchantId, $paymentId);

    expect($found)->toBeInstanceOf(ProjectedPayment::class);

    // The whole object, by value: a sixth public field added later fails here
    // rather than appearing in a response nobody re-read.
    expect((array) $found)->toBe([
        'paymentId' => $paymentId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'initiatedAt' => '2026-08-21T10:11:12.123456+00:00',
    ]);

    // PDO's pgsql driver may hand a bigint back as a string; the port promises int.
    expect($found->amountMinor)->toBeInt();
});

it('preserves the stored instant as UTC with six fractional digits', function (string $stored, string $expected): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId, initiatedAt: $stored);

    $found = paymentQueryAdapter()->findOwned($merchantId, $paymentId);

    expect($found)->toBeInstanceOf(ProjectedPayment::class)
        ->and($found->initiatedAt)->toBe($expected);
})->with([
    // Microseconds survive rather than rounding to whole seconds.
    ['2026-08-21T10:11:12.123456+00:00', '2026-08-21T10:11:12.123456+00:00'],
    // A whole second still renders six digits, so the shape never varies.
    ['2026-08-21T10:11:12+00:00', '2026-08-21T10:11:12.000000+00:00'],
    // A non-UTC offset is normalised, not reinterpreted: the same instant.
    ['2026-08-21T12:11:12.123456+02:00', '2026-08-21T10:11:12.123456+00:00'],
]);

it('short-circuits the receipt fallback when the projection exists', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    // BOTH rows exist, which is the normal steady state: a projected payment keeps
    // its idempotency receipt forever.
    paymentQueryProjection($merchantId, $paymentId);
    paymentQueryReceipt($merchantId, $paymentId);

    $statements = paymentQueryStatements();
    $found = paymentQueryAdapter()->findOwned($merchantId, $paymentId);

    expect($found)->toBeInstanceOf(ProjectedPayment::class);

    // One statement, and it is the projection lookup. An implementation that read
    // the receipt anyway would answer identically and fail only here.
    expect(paymentQuerySql($statements))->toHaveCount(1);
    expect(paymentQuerySql($statements)[0])->toContain('payment_details')
        ->not->toContain('payment_idempotency_receipts');
});

it('falls back to the owned receipt when the projection has not caught up', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryReceipt($merchantId, $paymentId);

    $found = paymentQueryAdapter()->findOwned($merchantId, $paymentId);

    expect($found)->toBeInstanceOf(PendingPayment::class)
        ->and((array) $found)->toBe(['paymentId' => $paymentId]);
});

it('refuses a payment id neither table knows', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, (string) Str::uuid7()))
        ->toThrow(PaymentNotFound::class);
});

it('refuses another merchant projection and answers the owner normally', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $stranger = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($owner, $paymentId);

    expect(fn () => paymentQueryAdapter()->findOwned($stranger, $paymentId))
        ->toThrow(PaymentNotFound::class);

    // The positive control is what makes the refusal non-vacuous: the row is
    // genuinely there and genuinely readable, by its owner.
    expect(paymentQueryAdapter()->findOwned($owner, $paymentId))
        ->toBeInstanceOf(ProjectedPayment::class);
});

it('refuses another merchant pending receipt and answers the owner normally', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $stranger = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryReceipt($owner, $paymentId);

    expect(fn () => paymentQueryAdapter()->findOwned($stranger, $paymentId))
        ->toThrow(PaymentNotFound::class);

    expect(paymentQueryAdapter()->findOwned($owner, $paymentId))
        ->toBeInstanceOf(PendingPayment::class);
});

it('refuses a malformed payment id without letting PostgreSQL see it', function (string $malformed): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $statements = paymentQueryStatements();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $malformed))
        ->toThrow(PaymentNotFound::class);

    // NO STATEMENT AT ALL, which is stronger than "no 22P02 surfaced": a route
    // constraint or a caught QueryException would both leave a statement behind.
    expect(paymentQuerySql($statements))->toBe([]);
})->with([
    'plain text' => ['not-a-uuid'],
    'truncated uuid' => ['0199a0f0-1111-7000-8000-00000000'],
    'sql fragment' => ["' or '1'='1"],
    'empty' => [''],
]);

it('runs both lookups in one transaction on the injected connection', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    // Receipt only, so the fallback is genuinely reached and there are two
    // statements to place inside one transaction.
    paymentQueryReceipt($merchantId, $paymentId);

    $begun = 0;
    $committed = 0;
    Event::listen(TransactionBeginning::class, function () use (&$begun): void {
        $begun++;
    });
    Event::listen(TransactionCommitted::class, function () use (&$committed): void {
        $committed++;
    });

    $statements = paymentQueryStatements();
    $adapter = paymentQueryAdapter();

    expect($adapter->findOwned($merchantId, $paymentId))->toBeInstanceOf(PendingPayment::class);

    // Exactly one transaction, opened and committed by the adapter. Under a
    // RefreshDatabase wrapper this would be a savepoint and neither event fires.
    expect($begun)->toBe(1)
        ->and($committed)->toBe(1);

    // Both statements ran at depth 1 - inside that transaction, not around it.
    $rows = iterator_to_array($statements);
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'level'))->toBe([1, 1])
        ->and(array_unique(array_column($rows, 'connection')))->toBe([DB::getDefaultConnection()]);

    // THE INJECTED CONNECTION IS THE DEFAULT CONNECTION OBJECT, by identity rather
    // than by name. That is what T11 relies on when it prepends a connection-affine
    // gate to this same transaction: a second backend would hold a different lock.
    $connection = new ReflectionProperty($adapter, 'connection')->getValue($adapter);
    expect($connection)->toBe(DB::connection());
});

it('reads only the two allowed tables and writes nothing', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $projected = (string) Str::uuid7();
    $pending = (string) Str::uuid7();

    paymentQueryProjection($owner, $projected);
    paymentQueryReceipt($owner, $pending);

    $statements = paymentQueryStatements();
    $adapter = paymentQueryAdapter();

    // Every reachable state, so the claim covers the fallback and the refusal too.
    $adapter->findOwned($owner, $projected);
    $adapter->findOwned($owner, $pending);
    expect(fn () => $adapter->findOwned($owner, (string) Str::uuid7()))
        ->toThrow(PaymentNotFound::class);

    $sql = paymentQuerySql($statements);
    expect($sql)->not->toBeEmpty();

    foreach ($sql as $statement) {
        // READ-ONLY, ASSERTED ON THE VERB. An adapter that cached a lookup by
        // writing a row would be caught here and nowhere else in this file.
        expect($statement)->toStartWith('select');

        // The forbidden tables are named individually so a failure says which one
        // the query path learned to touch.
        foreach (['stored_events', 'payment_outbox', 'payment_projector_receipts', 'snapshots', 'merchants', 'users'] as $forbidden) {
            expect($statement)->not->toContain($forbidden);
        }
    }
});
