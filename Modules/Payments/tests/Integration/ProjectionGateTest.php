<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionGateState;
use Modules\Payments\Tests\Integration\AdvisoryExclusiveWaiter;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;

function gate(): ProjectionGate
{
    return new ProjectionGate(DB::connection());
}

function markRebuilding(?string $clearedAt = null): string
{
    $token = (string) Str::uuid7();

    DB::table('payment_projection_controls')->where('projection', ProjectionGate::PAYMENT_DETAILS)->update([
        'state' => ProjectionGate::REBUILDING,
        'owner_token' => $token,
        'owner_pid' => ProjectionGateLocks::pid(IntegrationDatabase::LOCK),
        'started_at' => DB::raw('now()'),
        'cleared_at' => $clearedAt,
        'updated_at' => DB::raw('now()'),
    ]);

    return $token;
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();

    // Advisory locks never conflict within one session, so these connections must be distinct backends.
    expect(ProjectionGateLocks::pid(DB::getDefaultConnection()))
        ->not->toBe(ProjectionGateLocks::pid(IntegrationDatabase::LOCK));

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
});

afterEach(function (): void {
    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();

    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    ProjectionGateLocks::releaseAll(DB::getDefaultConnection());

    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('reports an open gate and holds the shared lock on the backend that checked it', function (): void {
    $observedPid = null;
    $locksWhileHeld = [];

    DB::connection()->beginTransaction();

    $state = gate()->inspectPaymentDetails();

    $observedPid = ProjectionGateLocks::pid(DB::getDefaultConnection());
    $locksWhileHeld = ProjectionGateLocks::onKey(
        ProjectionGate::CLASS_ID,
        ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
    );

    DB::connection()->commit();

    expect($state)->toBe(ProjectionGateState::Open)
        ->and($state->isOpen())->toBeTrue();

    expect($locksWhileHeld)->toHaveCount(1);
    expect($locksWhileHeld[0]['pid'])->toBe($observedPid);
    expect($locksWhileHeld[0]['mode'])->toBe('ShareLock');
    expect($locksWhileHeld[0]['granted'])->toBeTrue();
    expect($locksWhileHeld[0]['classid'])->toBe(ProjectionGate::CLASS_ID);
    expect($locksWhileHeld[0]['objid'])->toBe(ProjectionGate::PAYMENT_DETAILS_OBJECT_ID);

    // objsubid 2 is PostgreSQL's marker for the two-integer key form.
    expect($locksWhileHeld[0]['objsubid'])->toBe(2);

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
});

it('releases the shared lock on rollback as well as on commit', function (): void {
    DB::connection()->beginTransaction();

    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);
    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toHaveCount(1);

    DB::connection()->rollBack();

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
    expect(DB::connection()->transactionLevel())->toBe(0);
});

it('admits several traffic paths through the shared gate at once', function (): void {
    DB::connection()->beginTransaction();
    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);

    DB::connection(IntegrationDatabase::LOCK)->beginTransaction();
    $second = new ProjectionGate(DB::connection(IntegrationDatabase::LOCK));
    expect($second->inspectPaymentDetails())->toBe(ProjectionGateState::Open);

    $locks = ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID);

    expect($locks)->toHaveCount(2);
    expect($locks[0]['mode'])->toBe('ShareLock');
    expect($locks[1]['mode'])->toBe('ShareLock');
    expect($locks[0]['granted'])->toBeTrue();
    expect($locks[1]['granted'])->toBeTrue();
    expect($locks[0]['pid'])->not->toBe($locks[1]['pid']);

    DB::connection(IntegrationDatabase::LOCK)->rollBack();
    DB::connection()->rollBack();
});

it('closes the gate on the lock while a rebuild holds it exclusively', function (): void {
    DB::connection(IntegrationDatabase::LOCK)
        ->select(sprintf(
            'select pg_advisory_lock(%d, %d)',
            ProjectionGate::CLASS_ID,
            ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        ));

    DB::connection()->beginTransaction();

    $state = gate()->inspectPaymentDetails();

    DB::connection()->rollBack();

    expect($state)->toBe(ProjectionGateState::ClosedByRebuildLock)
        ->and($state->isOpen())->toBeFalse();

    expect(DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value('state'))->toBe(ProjectionGate::READY);

    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);

    DB::connection()->beginTransaction();
    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);
    DB::connection()->rollBack();
});

it('closes the gate on the control row when the lock is free', function (): void {
    markRebuilding(clearedAt: null);

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);

    DB::connection()->beginTransaction();
    $state = gate()->inspectPaymentDetails();
    DB::connection()->rollBack();

    expect($state)->toBe(ProjectionGateState::ClosedByControlState)
        ->and($state->isOpen())->toBeFalse();
});

it('closes the gate when the control row is missing entirely', function (): void {
    DB::table('payment_projection_controls')->where('projection', ProjectionGate::PAYMENT_DETAILS)->delete();

    DB::connection()->beginTransaction();
    $state = gate()->inspectPaymentDetails();
    DB::connection()->rollBack();

    expect($state)->toBe(ProjectionGateState::ClosedByMissingControlRow)
        ->and($state->isOpen())->toBeFalse();

    DB::table('payment_projection_controls')->insert([
        'projection' => ProjectionGate::PAYMENT_DETAILS,
        'state' => ProjectionGate::READY,
        'created_at' => DB::raw('now()'),
        'updated_at' => DB::raw('now()'),
    ]);
});

it('refuses to check the gate outside a transaction', function (): void {
    // Outside a transaction the transaction-scoped lock would be released at the end of its own statement.
    expect(DB::connection()->transactionLevel())->toBe(0);

    expect(fn () => gate()->inspectPaymentDetails())
        ->toThrow(RuntimeException::class);

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
});

/**
 * @param  array<string, mixed>  $values
 * @return array{sql_state: ?string, message: string}
 */
function controlRowViolation(array $values): array
{
    try {
        DB::table('payment_projection_controls')
            ->where('projection', ProjectionGate::PAYMENT_DETAILS)
            ->update($values);

        return ['sql_state' => null, 'message' => ''];
    } catch (QueryException $exception) {
        return [
            'sql_state' => RetryingAtomicTransaction::sqlStateOf($exception),
            'message' => $exception->getMessage(),
        ];
    }
}

it('refuses a state outside the allowed set', function (): void {
    $violation = controlRowViolation(['state' => 'draining']);

    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_state_allowed');
});

it('refuses a ready projection that still carries ownership or a clear marker', function (string $column, mixed $value): void {
    $violation = controlRowViolation([$column => $value]);

    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_ready_unowned');

    expect(DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value($column))->toBeNull();
})->with([
    'an owner token' => ['owner_token', '019299bb-0000-7000-8000-000000000000'],
    'an owner pid' => ['owner_pid', 4242],
    'a start time' => ['started_at', '2026-09-03 00:00:00+00'],
    'a clear marker' => ['cleared_at', '2026-09-03 00:00:00+00'],
]);

it('refuses a rebuilding projection with no owner', function (): void {
    $violation = controlRowViolation(['state' => ProjectionGate::REBUILDING]);

    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_rebuilding_owned');
});

it('permits a rebuilding projection to carry a clear marker or not', function (?string $clearedAt): void {
    markRebuilding(clearedAt: $clearedAt);

    $stored = DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value('cleared_at');

    if ($clearedAt === null) {
        expect($stored)->toBeNull();
    } else {
        expect($stored)->not->toBeNull();
    }
})->with([null, '2026-09-03 00:00:00+00']);

it('records what a shared try-lock does while an exclusive request is queued', function (): void {
    DB::connection()->beginTransaction();
    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);

    $waiter = new AdvisoryExclusiveWaiter(
        ProjectionGate::CLASS_ID,
        ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
    );

    try {
        $waiter->start();

        $queued = ProjectionGateLocks::onKey(
            ProjectionGate::CLASS_ID,
            ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        );

        $granted = array_values(array_filter($queued, static fn (array $l): bool => $l['granted']));
        $waiting = array_values(array_filter($queued, static fn (array $l): bool => ! $l['granted']));

        expect($granted)->toHaveCount(1);
        expect($granted[0]['mode'])->toBe('ShareLock');
        expect($waiting)->toHaveCount(1);
        expect($waiting[0]['mode'])->toBe('ExclusiveLock');
        expect($waiting[0]['pid'])->not->toBe($granted[0]['pid']);

        DB::connection(IntegrationDatabase::LOCK)->beginTransaction();

        $thirdParty = new ProjectionGate(DB::connection(IntegrationDatabase::LOCK));
        $observed = $thirdParty->inspectPaymentDetails();

        DB::connection(IntegrationDatabase::LOCK)->rollBack();
    } finally {
        $waiter->stop();
        DB::connection()->rollBack();
    }

    // Refused while an exclusive request is queued, so traffic cannot starve a pending rebuild (ADR 0005).
    expect($observed)->toBe(ProjectionGateState::ClosedByRebuildLock);

    DB::connection(IntegrationDatabase::LOCK)->beginTransaction();
    $afterWaiterLeft = new ProjectionGate(DB::connection(IntegrationDatabase::LOCK))->inspectPaymentDetails();
    DB::connection(IntegrationDatabase::LOCK)->rollBack();

    expect($afterWaiterLeft)->toBe(ProjectionGateState::Open);
});
