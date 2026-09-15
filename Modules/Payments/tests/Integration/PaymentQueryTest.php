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
use Modules\Payments\Application\Query\ProjectionRebuilding;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;

function paymentQueryAdapter(): PaymentQuery
{
    return resolve(PaymentQuery::class);
}

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

/** Captures transaction depth at execution time: afterwards it is 0 for correct and broken adapters alike. */
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

    expect((array) $found)->toBe([
        'paymentId' => $paymentId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'initiatedAt' => '2026-08-21T10:11:12.123456+00:00',
    ]);

    // PDO may return a bigint as a string; the port promises int.
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
    'microseconds survive' => ['2026-08-21T10:11:12.123456+00:00', '2026-08-21T10:11:12.123456+00:00'],
    'a whole second keeps six digits' => ['2026-08-21T10:11:12+00:00', '2026-08-21T10:11:12.000000+00:00'],
    'a non-UTC offset becomes the same UTC instant' => ['2026-08-21T12:11:12.123456+02:00', '2026-08-21T10:11:12.123456+00:00'],
]);

it('normalises to UTC itself rather than trusting how the session renders the instant', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId);

    // The session is otherwise UTC, which would hide a missing ->utc() in the adapter.
    DB::statement("set time zone 'Europe/Kyiv'");

    expect(paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->initiatedAt->toBe('2026-08-21T10:11:12.123456+00:00');

    DB::statement("set time zone 'UTC'");
});

it('short-circuits the receipt fallback when the projection exists', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId);
    paymentQueryReceipt($merchantId, $paymentId);

    $statements = paymentQueryStatements();
    $found = paymentQueryAdapter()->findOwned($merchantId, $paymentId);

    expect($found)->toBeInstanceOf(ProjectedPayment::class);

    $sql = paymentQuerySql($statements);

    expect($sql)->toHaveCount(3)
        ->and($sql[0])->toContain('pg_try_advisory_xact_lock_shared')
        ->and($sql[1])->toContain('payment_projection_controls');
    expect($sql[2])->toContain('payment_details')
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

    expect(paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toBeInstanceOf(PendingPayment::class);

    $deleted = DB::table('payment_idempotency_receipts')
        ->where('merchant_id', $merchantId)
        ->where('payment_id', $paymentId)
        ->delete();

    expect($deleted)->toBe(1);

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toThrow(PaymentNotFound::class);
});

it('refuses a payment id neither table knows and leaves no transaction open', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, (string) Str::uuid7()))
        ->toThrow(PaymentNotFound::class);

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('refuses a malformed payment id without letting PostgreSQL see it', function (string $malformed): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $statements = paymentQueryStatements();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $malformed))
        ->toThrow(PaymentNotFound::class);

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

    expect($begun)->toBe(1)
        ->and($committed)->toBe(1);

    $rows = iterator_to_array($statements);
    expect($rows)->toHaveCount(4)
        ->and(array_column($rows, 'level'))->toBe([1, 1, 1, 1])
        ->and(array_unique(array_column($rows, 'connection')))->toBe([DB::getDefaultConnection()]);

    // By object identity: a second backend would hold a different gate lock.
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

    $adapter->findOwned($owner, $projected);
    $adapter->findOwned($owner, $pending);
    expect(fn () => $adapter->findOwned($owner, (string) Str::uuid7()))
        ->toThrow(PaymentNotFound::class);

    $sql = paymentQuerySql($statements);
    expect($sql)->not->toBeEmpty();

    $sawGateProbe = false;

    foreach ($sql as $statement) {
        expect($statement)->toStartWith('select');

        // The advisory-lock probe names no table; it is recorded so it cannot silently stop running.
        if (str_contains($statement, 'pg_try_advisory_xact_lock_shared')) {
            $sawGateProbe = true;

            continue;
        }

        // An allowlist over every quoted or bare table in FROM and JOIN, so an unpredicted table fails too.
        preg_match_all('/(?:from|join)\s+"?([a-z_]+)"?/i', $statement, $tables);

        expect($tables[1])->not->toBeEmpty()
            ->and(array_values(array_diff(
                array_unique($tables[1]),
                ['payment_details', 'payment_idempotency_receipts', 'payment_projection_controls'],
            )))->toBe([]);
    }

    expect($sawGateProbe)->toBeTrue();
});

function paymentQueryCloseGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);
}

function paymentQueryReopenGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'ready',
        'owner_token' => null,
        'owner_pid' => null,
        'started_at' => null,
        'cleared_at' => null,
        'updated_at' => now(),
    ]);
}

it('refuses a read with ProjectionRebuilding before either table is touched, and leaves no transaction open', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryProjection($merchantId, $paymentId);

    paymentQueryCloseGate();

    $statements = paymentQueryStatements();

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toThrow(ProjectionRebuilding::class);

    $sql = paymentQuerySql($statements);

    expect($sql)->toHaveCount(2)
        ->and($sql[0])->toContain('pg_try_advisory_xact_lock_shared')
        ->and($sql[1])->toContain('payment_projection_controls')
        ->and(implode(' ', $sql))->not->toContain('payment_details')
        ->and(implode(' ', $sql))->not->toContain('payment_idempotency_receipts');

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    paymentQueryReopenGate();
});

it('holds the gate lock on its own connection while a read is refused by the control row', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();

    paymentQueryCloseGate();

    $lockDuringTransaction = [];

    // DB::listen(), not TransactionCommitting: the refused transaction rolls back.
    DB::listen(function ($query) use (&$lockDuringTransaction): void {
        if (str_contains($query->sql, 'payment_projection_controls')) {
            $lockDuringTransaction = ProjectionGateLocks::onKey(
                ProjectionGate::CLASS_ID,
                ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
            );
        }
    });

    expect(fn () => paymentQueryAdapter()->findOwned($merchantId, $paymentId))
        ->toThrow(ProjectionRebuilding::class);

    expect($lockDuringTransaction)->toHaveCount(1)
        ->and($lockDuringTransaction[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringTransaction[0]['granted'])->toBeTrue();

    $adapter = paymentQueryAdapter();
    $connection = new ReflectionProperty($adapter, 'connection')->getValue($adapter);
    expect($connection)->toBe(DB::connection());

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);

    paymentQueryReopenGate();
});
