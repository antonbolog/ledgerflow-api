<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Payments\Tests\Spike\SpikeAdvisoryLock;
use Modules\Payments\Tests\Spike\SpikeDatabase;
use Modules\Payments\Tests\Spike\SpikeFailureSwitch;
use Modules\Payments\Tests\Spike\SpikeInjectedFailure;
use Modules\Payments\Tests\Spike\SpikeRelayAttempt;
use Modules\Payments\Tests\Spike\SpikeRelayJob;
use Modules\Payments\Tests\Spike\SpikeRelayQueue;

/*
|--------------------------------------------------------------------------
| T4 Q9/Q10 — immediate Redis publication and the safe-duplicate boundary
|--------------------------------------------------------------------------
|
|   Q9   Does publication happen IMMEDIATELY, while the PostgreSQL transaction
|        is still open, rather than through an after-commit callback?
|   Q10  Does a crash AFTER an accepted publication leave the canonical row in
|        its pre-attempt eligible state, so a later pass republishes?
|
| Q9's evidence is cross-store and third-party: Redis holds the message while
| the OBSERVER connection still reads the pre-update row. Uncommitted changes
| are visible only to the session that made them, so that pair is PostgreSQL's
| own statement that publication preceded commit. An after_commit push cannot
| satisfy it, which is what the deferred twin below exists to prove.
|
| Q10 starts from a COMMITTED eligible row. Crashing before the row exists
| would produce an orphan message — the separate "missing durable envelope"
| failure from projection-and-query.md, not the safe duplicate.
|
| push(), never pushRaw(): pushRaw bypasses the after_commit check entirely and
| is unconditionally immediate, so it is incapable of failing Q9.
|
*/

beforeEach(function (): void {
    SpikeDatabase::configure();
    SpikeDatabase::ensureSchema();

    // Before anything resolves the Redis manager or a queue connection.
    SpikeRelayQueue::configure();

    // --- the six universal PostgreSQL guards ---------------------------------
    expect(SpikeAdvisoryLock::pid(SpikeDatabase::CONNECTION))
        ->not->toBe(SpikeAdvisoryLock::pid(SpikeDatabase::LOCK_CONNECTION));

    expect(SpikeDatabase::databaseName(SpikeDatabase::CONNECTION))->toBe('ledgerflow_test');
    expect(SpikeDatabase::databaseName(SpikeDatabase::LOCK_CONNECTION))->toBe('ledgerflow_test');

    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0);
    expect(DB::connection(SpikeDatabase::LOCK_CONNECTION)->transactionLevel())->toBe(0);
    expect(DB::connection()->transactionLevel())->toBe(1);

    expect(SpikeAdvisoryLock::heldLocks())->toBe([]);

    $rowCounts = SpikeDatabase::observedRowCounts();
    expect($rowCounts)->toHaveKey('spike_outbox_delivery');
    expect($rowCounts)->each->toBe(0);

    // --- the transport guards ------------------------------------------------
    // A configuration guard proving the per-connection database override took.
    // It is NOT a licence to delete: cleanup removes only the eight keys the
    // spike itself creates.
    expect(SpikeRelayQueue::dbNum())->toBe(SpikeRelayQueue::DATABASE);

    // MEASURED, and not what the plan assumed: phpunit.xml declares
    // QUEUE_CONNECTION=sync with force="true", but that writes only $_ENV and
    // putenv(). Compose's env_file puts QUEUE_CONNECTION=redis into $_SERVER,
    // which Laravel's env resolver reads FIRST, and tests/bootstrap.php forces
    // only the DB_* keys back. config('queue.default') is therefore 'redis'
    // here, so the DEFAULT queue connection is itself a RedisQueue pointed at
    // logical database 0 — the database a developer's stack uses.
    //
    // A class check alone therefore cannot tell the spike connection apart from
    // the default one. These guards name the connection and prove its own Redis
    // client selected database 15, which is what actually keeps the spike off
    // the shared keyspace.
    $immediate = Queue::connection(SpikeRelayQueue::CONNECTION);
    $deferred = Queue::connection(SpikeRelayQueue::DEFERRED_CONNECTION);

    expect($immediate)->toBeInstanceOf(RedisQueue::class);
    expect($deferred)->toBeInstanceOf(RedisQueue::class);
    expect($immediate->getConnectionName())->toBe(SpikeRelayQueue::CONNECTION);
    expect($deferred->getConnectionName())->toBe(SpikeRelayQueue::DEFERRED_CONNECTION);
    expect((int) $immediate->getConnection()->client()->getDbNum())->toBe(SpikeRelayQueue::DATABASE);
    expect((int) $deferred->getConnection()->client()->getDbNum())->toBe(SpikeRelayQueue::DATABASE);

    expect(SpikeRelayQueue::existingKeys())->toBe([]);
});

afterEach(function (): void {
    SpikeRelayQueue::teardown();
    SpikeDatabase::teardown();
});

/*
| Q9 — publication happens inside the still-open transaction
*/

it('publishes to Redis while the PostgreSQL transaction is still open', function (): void {
    $eventId = Str::uuid()->toString();

    DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
        'event_id' => $eventId,
    ]);

    $queued = [];
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued): void {
        $queued[] = [
            'connection' => $event->connectionName,
            'queue' => $event->queue,
            'spike_transaction_level' => DB::connection(SpikeDatabase::CONNECTION)->transactionLevel(),
        ];
    });

    $inside = [];

    DB::connection(SpikeDatabase::CONNECTION)->transaction(function () use ($eventId, &$inside): void {
        DB::connection(SpikeDatabase::CONNECTION)
            ->table('spike_outbox_delivery')
            ->where('event_id', $eventId)
            ->whereNull('last_published_at')
            ->lockForUpdate()
            ->first();

        Queue::connection(SpikeRelayQueue::CONNECTION)->push(
            new SpikeRelayJob($eventId),
            '',
            SpikeRelayQueue::QUEUE,
        );

        DB::connection(SpikeDatabase::CONNECTION)
            ->table('spike_outbox_delivery')
            ->where('event_id', $eventId)
            ->update([
                'last_published_at' => now(),
                'publish_count' => 1,
            ]);

        // PRIMARY EVIDENCE, captured while the transaction is still open.
        $inside = [
            'redis_size' => SpikeRelayQueue::size(SpikeRelayQueue::QUEUE),
            'redis_pending_size' => SpikeRelayQueue::pendingSize(SpikeRelayQueue::QUEUE),
            'redis_pending_jobs' => SpikeRelayQueue::pendingJobs(SpikeRelayQueue::QUEUE)->count(),
            'observer_last_published_at' => DB::connection()
                ->table('spike_outbox_delivery')
                ->where('event_id', $eventId)
                ->value('last_published_at'),
            'observer_publish_count' => (int) DB::connection()
                ->table('spike_outbox_delivery')
                ->where('event_id', $eventId)
                ->value('publish_count'),
            'keys' => SpikeRelayQueue::existingKeys(),
            'payload' => SpikeRelayQueue::pendingJobs(SpikeRelayQueue::QUEUE)->first()->payload,
        ];
    }, 1);

    // Redis had the message; PostgreSQL had not committed.
    expect($inside['redis_size'])->toBe(1);
    expect($inside['observer_last_published_at'])->toBeNull();
    expect($inside['observer_publish_count'])->toBe(0);

    // Prefix-divergence detector: push and size() run through Lua eval, while
    // pendingSize() and pendingJobs() run through ordinary prefixed commands.
    // Agreement is the assertion; divergence would otherwise read an empty key
    // and silently report zero.
    expect($inside['redis_pending_size'])->toBe($inside['redis_size']);
    expect($inside['redis_pending_jobs'])->toBe($inside['redis_size']);

    // The literal keys a real push populates, measured rather than assumed.
    expect($inside['keys'])->toBe([
        'queues:t4spike:relay',
        'queues:t4spike:relay:notify',
    ]);

    // The relay publishes an ID. The consumer loads the canonical envelope from
    // PostgreSQL, so no business field may ride along.
    expect($inside['payload']['displayName'])->toBe(SpikeRelayJob::class);
    expect($inside['payload']['data']['commandName'])->toBe(SpikeRelayJob::class);
    expect($inside['payload']['data']['command'])->toContain($eventId);

    $encoded = json_encode($inside['payload']);
    foreach (['amount', 'currency', 'merchant', 'occurred_at', 'aggregate'] as $businessField) {
        expect($encoded)->not->toContain($businessField);
    }

    // The dispatch happened on the spike queue connection, inside the
    // transaction, not deferred past it.
    expect($queued)->toHaveCount(1);
    expect($queued[0]['connection'])->toBe(SpikeRelayQueue::CONNECTION);
    expect($queued[0]['queue'])->toBe(SpikeRelayQueue::QUEUE);
    expect($queued[0]['spike_transaction_level'])->toBe(1);

    // After commit the row is visible and the message is still there.
    expect(SpikeRelayQueue::size(SpikeRelayQueue::QUEUE))->toBe(1);
    expect(DB::connection()->table('spike_outbox_delivery')->where('event_id', $eventId)->value('last_published_at'))
        ->not->toBeNull();
});

it('defers the after_commit twin until the transaction commits', function (): void {
    // Without this control, the test above proves nothing: it would pass even
    // on a stack where after_commit could not defer at all.
    $eventId = Str::uuid()->toString();

    DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
        'event_id' => $eventId,
    ]);

    $queued = [];
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$queued): void {
        $queued[] = [
            'connection' => $event->connectionName,
            'spike_transaction_level' => DB::connection(SpikeDatabase::CONNECTION)->transactionLevel(),
        ];
    });

    $sizeInside = null;
    $pendingOwner = null;

    DB::connection(SpikeDatabase::CONNECTION)->transaction(function () use ($eventId, &$sizeInside, &$pendingOwner): void {
        // CONNECTION LOCALITY IS ASSERTED, NOT ASSUMED.
        //
        // Every connection shares one singleton DatabaseTransactionsManager, and
        // addCallback() attaches to getPendingTransactions()->last() across ALL
        // connections. The callback lands on the spike transaction only because
        // the spike began last, which is accidental locality. If anything ever
        // opened another transaction between beginTransaction and this push, the
        // callback would attach to RefreshDatabase's wrapper instead, where it
        // never fires because that transaction is never committed.
        $pending = resolve('db.transactions')->getPendingTransactions();
        $pendingOwner = $pending->last()->connection;

        Queue::connection(SpikeRelayQueue::DEFERRED_CONNECTION)->push(
            new SpikeRelayJob($eventId),
            '',
            SpikeRelayQueue::DEFERRED_QUEUE,
        );

        $sizeInside = SpikeRelayQueue::size(SpikeRelayQueue::DEFERRED_QUEUE);
    }, 1);

    expect($pendingOwner)->toBe(SpikeDatabase::CONNECTION);
    expect($sizeInside)->toBe(0);
    expect(SpikeRelayQueue::size(SpikeRelayQueue::DEFERRED_QUEUE))->toBe(1);

    expect($queued)->toHaveCount(1);
    expect($queued[0]['connection'])->toBe(SpikeRelayQueue::DEFERRED_CONNECTION);
    expect($queued[0]['spike_transaction_level'])->toBe(0);
});

/*
| The switch itself. Q10 runs one attempt twice and changes only this state, so
| if disarm() did not work the second pass would throw and the duplicate could
| never appear — or, worse, a silently replaced switch would let pass 2 succeed
| for a reason that has nothing to do with the injected failure.
*/

it('disarms the failure switch so the same boundary stops throwing', function (): void {
    $switch = new SpikeFailureSwitch;

    $switch->arm();

    expect(fn () => $switch->trip('after-publish'))->toThrow(SpikeInjectedFailure::class);

    // arm() stays armed after trip(), so "not arming it again" is NOT enough.
    expect(fn () => $switch->trip('after-publish'))->toThrow(SpikeInjectedFailure::class);

    $switch->disarm();

    $switch->trip('after-publish');

    expect($switch->trippedAt())->toBe('after-publish');
});

/*
| Q10 — the safe-duplicate boundary
*/

it('leaves the row eligible after a crash and republishes on a later pass', function (): void {
    $eventId = Str::uuid()->toString();

    // A COMMITTED, eligible row. This is what makes the crash window the
    // documented one rather than an orphan message.
    DB::connection(SpikeDatabase::CONNECTION)->table('spike_outbox_delivery')->insert([
        'event_id' => $eventId,
    ]);

    $arranged = DB::connection()->table('spike_outbox_delivery')->where('event_id', $eventId)->first();
    expect($arranged)->not->toBeNull();
    expect($arranged->last_published_at)->toBeNull();
    expect((int) $arranged->publish_count)->toBe(0);

    $switch = resolve(SpikeFailureSwitch::class);
    $attempt = new SpikeRelayAttempt($eventId, $switch);

    // ONE attempt object, ONE runner closure, invoked twice. The only state
    // that differs between the passes is the switch.
    $runAttempt = fn (): mixed => DB::connection(SpikeDatabase::CONNECTION)
        ->transaction(fn () => $attempt(), 1);

    // --- pass 1: crash after the publication is accepted ---------------------
    $switch->arm();

    $thrown = null;

    try {
        $runAttempt();
    } catch (SpikeInjectedFailure $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SpikeInjectedFailure::class);
    expect($switch->trippedAt())->toBe('after-publish');
    expect(DB::connection(SpikeDatabase::CONNECTION)->transactionLevel())->toBe(0);

    // The publication survived: Redis is not transactional.
    expect(SpikeRelayQueue::size(SpikeRelayQueue::QUEUE))->toBe(1);

    // ...and the canonical row is back in its PRE-ATTEMPT ELIGIBLE state. A row
    // left marked published would mean the event is never redelivered: silent
    // loss, the exact failure the outbox exists to prevent.
    $afterPassOne = DB::connection()->table('spike_outbox_delivery')->where('event_id', $eventId)->first();

    expect($afterPassOne)->not->toBeNull();
    expect($afterPassOne->last_published_at)->toBeNull();
    expect((int) $afterPassOne->publish_count)->toBe(0);

    // --- pass 2: the same attempt, committing this time ----------------------
    $switch->disarm();

    $runAttempt();

    // THE SAFE DUPLICATE: two accepted messages for one logical event.
    expect(SpikeRelayQueue::size(SpikeRelayQueue::QUEUE))->toBe(2);

    $messages = SpikeRelayQueue::pendingJobs(SpikeRelayQueue::QUEUE);
    expect($messages)->toHaveCount(2);
    foreach ($messages as $message) {
        expect($message->payload['data']['command'])->toContain($eventId);
    }

    // Pass 2 is also the control: it proves the committing path DOES update the
    // row, so pass 1's untouched row is a rollback result rather than a write
    // that never happened.
    $afterPassTwo = DB::connection()->table('spike_outbox_delivery')->where('event_id', $eventId)->first();

    expect($afterPassTwo->last_published_at)->not->toBeNull();
    expect((int) $afterPassTwo->publish_count)->toBe(1);
});
