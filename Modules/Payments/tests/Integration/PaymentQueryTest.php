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

it('normalises to UTC itself rather than trusting how the session renders the instant', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId);

    // WITHOUT THIS STATEMENT THE ->utc() CALL IS UNTESTABLE, which is how it was
    // found. The container sets no TZ and the pgsql connection config sets no
    // `timezone`, so the session TimeZone is UTC and `timestamptz` renders `+00`
    // whatever offset was inserted - the datasets above stay green with ->utc()
    // deleted, because PostgreSQL had already done its work. A non-UTC session is
    // the only condition under which the adapter's own normalisation is visible.
    DB::statement("set time zone 'Europe/Kyiv'");

    // The same instant PostgreSQL now renders as 13:11:12.123456+03.
    expect(paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->initiatedAt->toBe('2026-08-21T10:11:12.123456+00:00');

    // Not strictly required - afterEach purges the default connection, so the
    // session dies with it - but a leaked session setting is the kind of thing
    // that makes an unrelated test fail later for an unfindable reason.
    DB::statement("set time zone 'UTC'");
});

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

it('stops answering pending the moment the receipt it answered from is deleted', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryReceipt($merchantId, $paymentId);

    // The pending answer FIRST, so the refusal below cannot be the accident of a
    // fixture that never landed. This is the non-vacuity control for every
    // "refuses an unknown payment" assertion in this file: same merchant, same id,
    // one difference - the row.
    expect(paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toBeInstanceOf(PendingPayment::class);

    $deleted = DB::table('payment_idempotency_receipts')
        ->where('merchant_id', $merchantId)
        ->where('payment_id', $paymentId)
        ->delete();

    // Exactly the row the pending answer came from, and only it.
    expect($deleted)->toBe(1);

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toThrow(PaymentNotFound::class);
});

it('refuses a payment id neither table knows and leaves no transaction open', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, (string) Str::uuid7()))
        ->toThrow(PaymentNotFound::class);

    // THE MOST FREQUENTLY REACHED ERROR PATH, and the one that throws from inside
    // the transaction callback. Laravel rolls back before rethrowing, so this
    // holds - but an adapter that ever owned its BEGIN/COMMIT by hand would leak
    // an `idle in transaction` backend here and nowhere else in this file.
    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
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

        // AN ALLOWLIST, NOT A LIST OF TABLES WE HAPPENED TO THINK OF. Naming
        // forbidden tables individually would pass any table nobody predicted -
        // `payment_projection_controls` above all, which is exactly the read T11
        // prepends to this adapter. So every table the statement names is
        // collected and the set is required to be a subset of the two allowed
        // ones; `join` is matched as well as `from`, or a join would smuggle a
        // third table into a statement that still names an allowed one.
        preg_match_all('/(?:from|join)\s+"([^"]+)"/i', $statement, $tables);

        expect($tables[1])->not->toBeEmpty()
            ->and(array_values(array_diff(
                array_unique($tables[1]),
                ['payment_details', 'payment_idempotency_receipts'],
            )))->toBe([]);
    }
});
