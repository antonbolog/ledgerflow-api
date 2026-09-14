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

/*
|--------------------------------------------------------------------------
| T11 Slice 1 — the projection gate primitive
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE because every assertion here is about real advisory
| locks held by real backends across real transaction boundaries. Under
| RefreshDatabase the unit under test would run inside a wrapper transaction, its
| "transaction-scoped" locks would be scoped to that wrapper rather than to the
| gate check, and the contention evidence would be vacuous.
|
| EVIDENCE RULES, carried from T4's spike:
|
|   - pg_locks is read from the OBSERVER connection, never from the holder. An
|     observation of "who holds this lock" made by the holder is not an
|     observation.
|   - Contention is observed by try-lock (an immediate answer) or by a pg_locks
|     barrier, never by a sleep chosen to be long enough.
|   - Every assertion has a mutation that makes it fail. Where the mutation is
|     not another test, it is stated in the test itself.
|
*/

/** The unit under test, on the same default connection every Payments adapter holds. */
function gate(): ProjectionGate
{
    return new ProjectionGate(DB::connection());
}

/** Mark the projection as owned by a rebuild, satisfying the rebuilding CHECK. */
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

    // THE GUARD THAT KEEPS COEXISTENCE ASSERTIONS HONEST. PostgreSQL advisory
    // locks never conflict with the session that already holds them, so two
    // connections collapsed onto one backend would make the shared-coexistence
    // test pass vacuously.
    expect(ProjectionGateLocks::pid(DB::getDefaultConnection()))
        ->not->toBe(ProjectionGateLocks::pid(IntegrationDatabase::LOCK));

    // The key must start free, or a leaked lock from an earlier test would be
    // read as this test's contention.
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

/*
| The open gate, and connection affinity
*/

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

    // PRIMARY EVIDENCE: PostgreSQL's own record, read by a third party, matches
    // the backend that ran the check. This is the property T11 depends on for
    // all four traffic paths, and it is asserted rather than assumed.
    expect($locksWhileHeld)->toHaveCount(1);
    expect($locksWhileHeld[0]['pid'])->toBe($observedPid);
    expect($locksWhileHeld[0]['mode'])->toBe('ShareLock');
    expect($locksWhileHeld[0]['granted'])->toBeTrue();
    expect($locksWhileHeld[0]['classid'])->toBe(ProjectionGate::CLASS_ID);
    expect($locksWhileHeld[0]['objid'])->toBe(ProjectionGate::PAYMENT_DETAILS_OBJECT_ID);

    // The two-integer form, which is what makes the assertions above exact
    // rather than a comparison against a truncated bigint.
    expect($locksWhileHeld[0]['objsubid'])->toBe(2);

    // Transaction-scoped: gone on commit, with no explicit release.
    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
});

it('releases the shared lock on rollback as well as on commit', function (): void {
    DB::connection()->beginTransaction();

    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);
    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toHaveCount(1);

    DB::connection()->rollBack();

    // A traffic path that failed after checking the gate must not leave the key
    // held, or the next rebuild would block on a transaction nobody owns.
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

    // THE GATE IS SHARED: POST, the transactional GET, the relay and the consumer
    // must all pass simultaneously. Two distinct backends, both granted.
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

/*
| The closed gate: both halves, and each one's own failure
*/

it('closes the gate on the lock while a rebuild holds it exclusively', function (): void {
    // A rebuild-shaped holder: session-level exclusive on another backend.
    DB::connection(IntegrationDatabase::LOCK)
        ->select(sprintf(
            'select pg_advisory_lock(%d, %d)',
            ProjectionGate::CLASS_ID,
            ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        ));

    DB::connection()->beginTransaction();

    $state = gate()->inspectPaymentDetails();

    DB::connection()->rollBack();

    // CLOSED BY THE LOCK, with the control row still saying `ready`. That
    // combination is what proves this half is load-bearing on its own: the row
    // check below could not have produced this answer.
    expect($state)->toBe(ProjectionGateState::ClosedByRebuildLock)
        ->and($state->isOpen())->toBeFalse();

    expect(DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value('state'))->toBe(ProjectionGate::READY);

    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);

    // The mutation: the refusal above is only meaningful because the gate opens
    // once the holder lets go.
    DB::connection()->beginTransaction();
    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);
    DB::connection()->rollBack();
});

it('closes the gate on the control row when the lock is free', function (): void {
    // THE CASE THE LOCK CANNOT SEE, and the whole reason the durable row exists.
    // A rebuild whose backend went away leaves no lock at all while
    // payment_details is still half-cleared.
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

    // NEVER `ready`. A projection whose control state cannot be read is not a
    // projection anyone should be reading, and answering open here would defeat
    // the point of having durable state at all.
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
    // NOT DEFENSIVE HABIT. A transaction-scoped advisory lock taken outside a
    // transaction is released at the end of its own statement, so the check
    // would report an open gate while holding nothing at all — a silent, total
    // failure of the mechanism.
    expect(DB::connection()->transactionLevel())->toBe(0);

    expect(fn () => gate()->inspectPaymentDetails())
        ->toThrow(RuntimeException::class);

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);
});

/*
| The control row's state machine, enforced by PostgreSQL rather than by review
*/

/**
 * Apply one control-row update and report the SQLSTATE and message it raised.
 *
 * THE SQLSTATE IS READ WITH THE SAME CLASSIFIER PRODUCTION USES, so a change to
 * that reader cannot silently stop these tests from checking what they claim.
 *
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

    // NAMED, not merely "some check failed". A third state added later must fail
    // here deliberately rather than pass because the message happened to change.
    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_state_allowed');
});

it('refuses a ready projection that still carries ownership or a clear marker', function (string $column, mixed $value): void {
    $violation = controlRowViolation([$column => $value]);

    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_ready_unowned');

    // The row is untouched, so the refusal cost nothing.
    expect(DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value($column))->toBeNull();
})->with([
    ['owner_token', '019299bb-0000-7000-8000-000000000000'],
    ['owner_pid', 4242],
    ['started_at', '2026-09-03 00:00:00+00'],
    // THE ONE THAT MATTERS MOST. A `ready` projection with a clear marker would
    // mean "a replay completed" and "the projection is cleared and unreplayed"
    // simultaneously, which is the contradiction guarded recovery reads. This
    // checks the ready-row shape; preserving the marker across takeovers is
    // the responsibility of rebuild and recovery code, not this CHECK.
    ['cleared_at', '2026-09-03 00:00:00+00'],
]);

it('refuses a rebuilding projection with no owner', function (): void {
    $violation = controlRowViolation(['state' => ProjectionGate::REBUILDING]);

    expect($violation['sql_state'])->toBe('23514')
        ->and($violation['message'])->toContain('payment_projection_controls_rebuilding_owned');
});

it('permits a rebuilding projection to carry a clear marker or not', function (?string $clearedAt): void {
    // BOTH ARE LEGAL, and which one holds IS the question guarded recovery asks.
    // A constraint forbidding either would make the recovery decision
    // unrepresentable.
    markRebuilding(clearedAt: $clearedAt);

    $stored = DB::table('payment_projection_controls')
        ->where('projection', ProjectionGate::PAYMENT_DETAILS)
        ->value('cleared_at');

    // AN EXPLICIT BRANCH, not expect()->when(). Rector caught the earlier version
    // passing a third argument to a two-argument method, which meant the non-null
    // case asserted nothing at all and the data set's second row was decorative.
    if ($clearedAt === null) {
        expect($stored)->toBeNull();
    } else {
        expect($stored)->not->toBeNull();
    }
})->with([null, '2026-09-03 00:00:00+00']);

/*
| D2 — what a shared try-lock does while an exclusive request is already waiting
|
| THE ONE ASSUMPTION ADR 0005 RECORDS AS UNMEASURED. Everything above describes
| behaviour T4 already proved on this stack; this test measures the interaction
| T4 never ran, and the plan requires it to be measured before anything depends
| on it.
*/

it('records what a shared try-lock does while an exclusive request is queued', function (): void {
    // 1. A traffic-shaped shared holder, so the exclusive request below must wait.
    DB::connection()->beginTransaction();
    expect(gate()->inspectPaymentDetails())->toBe(ProjectionGateState::Open);

    // 2. A real second process, blocked in PostgreSQL's lock queue. The barrier
    //    is pg_locks reporting an UNGRANTED exclusive row, not a sleep.
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

        // The situation is what the test claims it is: one granted shared holder
        // and one ungranted exclusive request, on two different backends.
        $granted = array_values(array_filter($queued, static fn (array $l): bool => $l['granted']));
        $waiting = array_values(array_filter($queued, static fn (array $l): bool => ! $l['granted']));

        expect($granted)->toHaveCount(1);
        expect($granted[0]['mode'])->toBe('ShareLock');
        expect($waiting)->toHaveCount(1);
        expect($waiting[0]['mode'])->toBe('ExclusiveLock');
        expect($waiting[0]['pid'])->not->toBe($granted[0]['pid']);

        // 3. A THIRD backend asks for the shared gate while that exclusive
        //    request sits in the queue. This boolean is the measurement.
        DB::connection(IntegrationDatabase::LOCK)->beginTransaction();

        $thirdParty = new ProjectionGate(DB::connection(IntegrationDatabase::LOCK));
        $observed = $thirdParty->inspectPaymentDetails();

        DB::connection(IntegrationDatabase::LOCK)->rollBack();
    } finally {
        $waiter->stop();
        DB::connection()->rollBack();
    }

    // THE MEASURED RESULT, asserted as a definite branch rather than as "one of
    // several outcomes". A different answer here is a finding about the lock
    // manager, not a flaky test.
    //
    // MEASURED on PostgreSQL 18.4 (aarch64, Alpine): a shared advisory try-lock
    // is REFUSED while an exclusive request for the same key is queued, even
    // though it does not conflict with the granted shared lock. PostgreSQL's
    // lock manager makes a request that conflicts with a pending waiter wait
    // rather than jump the queue, and a try-lock that would have to wait answers
    // false instead.
    //
    // This is the assumption ADR 0005 recorded as unmeasured, and it HOLDS. Two
    // consequences follow, and both are load-bearing for T11:
    //
    //   - No starvation. A rebuild's bounded wait cannot be extended
    //     indefinitely by continuous traffic, because traffic arriving after it
    //     queues is refused rather than admitted ahead of it.
    //   - The gate closes on a PENDING rebuild, not only on a holding one, so
    //     work stops draining into a projection that is about to be cleared
    //     before the clear begins.
    //
    // WHAT THIS DOES NOT ESTABLISH: it is a claim about this PostgreSQL major
    // version's lock manager, not a portable guarantee and not a fairness
    // ordering among several waiters.
    expect($observed)->toBe(ProjectionGateState::ClosedByRebuildLock);

    // THE MUTATION, in the same test so the refusal above cannot be vacuous. The
    // identical third-party check on the identical connection opens once the
    // queued exclusive request is gone. Without this line, a gate that refused
    // unconditionally would leave the assertion above green.
    DB::connection(IntegrationDatabase::LOCK)->beginTransaction();
    $afterWaiterLeft = new ProjectionGate(DB::connection(IntegrationDatabase::LOCK))->inspectPaymentDetails();
    DB::connection(IntegrationDatabase::LOCK)->rollBack();

    expect($afterWaiterLeft)->toBe(ProjectionGateState::Open);
});
