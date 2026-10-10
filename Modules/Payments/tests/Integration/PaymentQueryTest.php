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
use Modules\Payments\Application\Query\PendingIntent;
use Modules\Payments\Application\Query\PendingPayment;
use Modules\Payments\Application\Query\ProjectedIntent;
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

/**
 * @param  string  $createdAt  a timestamptz SQL expression, so ages are measured on the PostgreSQL clock
 * @return array{intent_id: string|null, payment_id: string}
 */
function paymentQueryIntentReceipt(
    int $merchantId,
    string $createdAt = 'statement_timestamp()',
    bool $withIntent = true,
    ?string $id = null,
): array {
    $paymentId = (string) Str::uuid7();
    $intentId = $withIntent ? (string) Str::uuid() : null;

    DB::table('payment_idempotency_receipts')->insert([
        'id' => $id ?? (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'idempotency_key_hash' => hash('sha256', $paymentId),
        'request_fingerprint' => str_repeat('b', 64),
        'payment_id' => $paymentId,
        'intent_id' => $intentId,
        'response_status' => 202,
        'response_body' => '{"payment_id":"'.$paymentId.'","status":"initiated"}',
        'response_location' => '/api/payments/'.$paymentId,
        'created_at' => DB::raw($createdAt),
        'updated_at' => DB::raw($createdAt),
    ]);

    return ['intent_id' => $intentId, 'payment_id' => $paymentId];
}

/** @return list<string> */
function paymentQueryRecentPaymentIds(int $merchantId): array
{
    return array_map(
        static fn (ProjectedIntent|PendingIntent $intent): string => $intent->paymentId,
        paymentQueryAdapter()->recentIntents($merchantId)->intents,
    );
}

it('lists a projected intent with its amount and a pending intent without one', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    /** @var string $instant */
    $instant = DB::scalar(<<<'SQL'
        select to_char((statement_timestamp() - interval '1 hour') at time zone 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"')
        SQL);

    $projected = paymentQueryIntentReceipt($merchantId, "'{$instant}'::timestamptz");
    $pending = paymentQueryIntentReceipt($merchantId, "'{$instant}'::timestamptz - interval '1 second'");
    paymentQueryProjection($merchantId, $projected['payment_id'], amountMinor: 4_200, currency: 'EUR');

    // The session is otherwise UTC, which would hide a missing ->utc() in the adapter.
    DB::statement("set time zone 'Europe/Kyiv'");
    $recent = paymentQueryAdapter()->recentIntents($merchantId);
    DB::statement("set time zone 'UTC'");

    expect($recent->truncated)->toBeFalse()
        ->and($recent->intents)->toHaveCount(2)
        ->and($recent->intents[0])->toBeInstanceOf(ProjectedIntent::class)
        ->and((array) $recent->intents[0])->toBe([
            'intentId' => $projected['intent_id'],
            'paymentId' => $projected['payment_id'],
            'createdAt' => $instant,
            'amountMinor' => 4_200,
            'currency' => 'EUR',
        ])
        ->and($recent->intents[1])->toBeInstanceOf(PendingIntent::class)
        ->and($recent->intents[1]->paymentId)->toBe($pending['payment_id'])
        ->and($recent->intents[1]->createdAt)->toEndWith('+00:00');
});

it('lists only the requested merchant intents', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $stranger = Merchant::factory()->create()->getKey();

    $own = paymentQueryIntentReceipt($owner);
    $foreign = paymentQueryIntentReceipt($stranger);

    expect(paymentQueryRecentPaymentIds($owner))->toBe([$own['payment_id']])
        ->and(paymentQueryRecentPaymentIds($stranger))->toBe([$foreign['payment_id']]);
});

it('never attaches another merchant amount, even to a receipt whose payment id it shares', function (): void {
    $owner = Merchant::factory()->create()->getKey();
    $stranger = Merchant::factory()->create()->getKey();

    // Inconsistent on purpose: no constraint ties a projection's merchant to its receipt's.
    $receipt = paymentQueryIntentReceipt($owner);
    paymentQueryProjection($stranger, $receipt['payment_id'], amountMinor: 9_999);

    $intents = paymentQueryAdapter()->recentIntents($owner)->intents;

    expect($intents)->toHaveCount(1)
        ->and($intents[0])->toBeInstanceOf(PendingIntent::class)
        ->and((array) $intents[0])->not->toHaveKey('amountMinor');

    expect(paymentQueryAdapter()->recentIntents($stranger)->intents)->toBe([]);
});

it('includes an intent just inside the 24-hour window and excludes one at its edge before the read', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    // Taken before the read, so the read's statement_timestamp() and its bound are strictly later.
    /** @var string $anchor */
    $anchor = DB::scalar('select clock_timestamp()::text');

    $outside = paymentQueryIntentReceipt($merchantId, "'{$anchor}'::timestamptz - interval '24 hours'");
    $inside = paymentQueryIntentReceipt($merchantId, "'{$anchor}'::timestamptz - interval '24 hours' + interval '5 seconds'");

    expect(paymentQueryRecentPaymentIds($merchantId))->toBe([$inside['payment_id']])
        ->and(paymentQueryRecentPaymentIds($merchantId))->not->toContain($outside['payment_id']);
});

it('bounds the window inclusively on the read statement clock', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $statements = paymentQueryStatements();
    paymentQueryAdapter()->recentIntents($merchantId);

    // statement_timestamp() cannot be pinned from outside the statement, so a one-microsecond
    // shift or an exclusive bound is only observable in the predicate itself.
    expect(paymentQuerySql($statements)[2])
        ->toContain("receipt.created_at >= statement_timestamp() - interval '24 hours' order by");
});

it('orders newest first, breaking equal timestamps by receipt id', function (array $planner): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $older = paymentQueryIntentReceipt($merchantId, "statement_timestamp() - interval '2 minutes'");
    $newest = paymentQueryIntentReceipt($merchantId, "statement_timestamp() - interval '10 seconds'");

    /** @var string $tie */
    $tie = DB::scalar("select (statement_timestamp() - interval '1 minute')::text");

    // Ascending ids inserted in ascending order, so neither insertion order nor a heap scan yields the expected order.
    $tied = array_map(
        static fn (int $n): string => paymentQueryIntentReceipt(
            $merchantId,
            "'{$tie}'::timestamptz",
            id: sprintf('00000000-0000-7000-8000-%012d', $n),
        )['payment_id'],
        range(1, 5),
    );

    foreach ($planner as $setting) {
        DB::statement("set {$setting} = off");
    }

    expect(paymentQueryRecentPaymentIds($merchantId))
        ->toBe([$newest['payment_id'], ...array_reverse($tied), $older['payment_id']]);

    foreach ($planner as $setting) {
        DB::statement("reset {$setting}");
    }
})->with([
    'planner default' => [[]],
    'heap scan and sort' => [['enable_indexscan', 'enable_bitmapscan', 'enable_indexonlyscan']],
]);

it('never lists a receipt written without an intent, even the newest one', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $withIntent = paymentQueryIntentReceipt($merchantId, "statement_timestamp() - interval '1 minute'");
    paymentQueryIntentReceipt($merchantId, withIntent: false);

    expect(paymentQueryRecentPaymentIds($merchantId))->toBe([$withIntent['payment_id']]);
});

it('returns at most 20 intents and reports whether an older one was cut off', function (int $qualifying, int $listed, bool $truncated): void {
    $merchantId = Merchant::factory()->create()->getKey();

    $paymentIds = array_map(
        static fn (int $age): string => paymentQueryIntentReceipt(
            $merchantId,
            "statement_timestamp() - interval '{$age} seconds'",
        )['payment_id'],
        $qualifying === 0 ? [] : range(1, $qualifying),
    );

    $recent = paymentQueryAdapter()->recentIntents($merchantId);

    expect($recent->truncated)->toBe($truncated)
        ->and(array_map(static fn (ProjectedIntent|PendingIntent $intent): string => $intent->paymentId, $recent->intents))
        ->toBe(array_slice($paymentIds, 0, $listed));
})->with([
    'none' => [0, 0, false],
    'exactly the limit' => [20, 20, false],
    'one beyond the limit' => [21, 20, true],
]);

it('does not report truncation for a 21st receipt the listing excludes', function (Closure $excluded): void {
    $merchantId = Merchant::factory()->create()->getKey();

    foreach (range(1, 20) as $age) {
        paymentQueryIntentReceipt($merchantId, "statement_timestamp() - interval '{$age} seconds'");
    }

    $excluded($merchantId);

    $recent = paymentQueryAdapter()->recentIntents($merchantId);

    expect($recent->intents)->toHaveCount(20)
        ->and($recent->truncated)->toBeFalse();
})->with([
    'older than 24 hours' => [fn (int $merchantId): array => paymentQueryIntentReceipt($merchantId, "statement_timestamp() - interval '25 hours'")],
    'without an intent' => [fn (int $merchantId): array => paymentQueryIntentReceipt($merchantId, withIntent: false)],
    'of another merchant' => [fn (): array => paymentQueryIntentReceipt(Merchant::factory()->create()->getKey())],
]);

it('lists in one transaction on the injected connection, gate first, and leaves none open', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    paymentQueryIntentReceipt($merchantId);

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

    expect($adapter->recentIntents($merchantId)->intents)->toHaveCount(1);

    $rows = iterator_to_array($statements);
    $sql = array_column($rows, 'sql');

    expect($begun)->toBe(1)
        ->and($committed)->toBe(1)
        ->and($rows)->toHaveCount(3)
        ->and(array_column($rows, 'level'))->toBe([1, 1, 1])
        ->and(array_unique(array_column($rows, 'connection')))->toBe([DB::getDefaultConnection()])
        ->and($sql[0])->toContain('pg_try_advisory_xact_lock_shared')
        ->and($sql[1])->toContain('payment_projection_controls')
        ->and($sql[2])->toStartWith('select')
        ->and($sql[2])->toContain('payment_idempotency_receipts');

    preg_match_all('/(?:from|join)\s+"?([a-z_]+)"?/i', $sql[2], $tables);
    expect($tables[1])->toBe(['payment_idempotency_receipts', 'payment_details']);

    expect(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('refuses the list with ProjectionRebuilding before either table is touched, and leaves no transaction open', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    paymentQueryIntentReceipt($merchantId);

    paymentQueryCloseGate();

    $statements = paymentQueryStatements();

    expect(fn () => paymentQueryAdapter()->recentIntents($merchantId))
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
