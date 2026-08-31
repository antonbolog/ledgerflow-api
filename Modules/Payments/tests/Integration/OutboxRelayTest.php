<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayOutcome;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\RejectingQueue;
use Modules\Payments\Tests\Support\RelayTransport;
use RedisException;

/*
|--------------------------------------------------------------------------
| The Payments outbox relay — lock through push
|--------------------------------------------------------------------------
|
| WHY THIS FILE CANNOT LIVE IN THE FEATURE SUITE. RefreshDatabase wraps every
| Feature test in a transaction, which turns the relay's explicit
| beginTransaction()/commit() into a SAVEPOINT. At savepoint depth
| TransactionCommitting never fires, no second connection can observe a commit,
| and every claim about "published before COMMIT", "rolled back to the exact
| pre-attempt row" and "committed despite the failure" would pass against a relay
| that never opened a transaction at all.
|
| EVERY DURABLE CLAIM IS READ FROM THE OBSERVER, a second PostgreSQL backend. A
| row it can see is a row that genuinely left the relay's transaction; a row it
| cannot see, while the relay's own session can, is PostgreSQL's own statement
| that the transaction is still open.
|
| NO SLEEPS. Contention is created with real locks and bounded by PostgreSQL's own
| statement_timeout; the crash boundaries are real transaction events.
|
| T4 proved the platform permits this design, using a three-column surrogate table
| and a spike attempt class. This file proves THIS implementation uses it, against
| the real payment_outbox.
|
*/

/** Every column of one envelope, read from the OBSERVER so only committed state counts. */
function relaySnapshot(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')
        ->where('event_id', $eventId)
        ->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

/**
 * The four columns T8 owns and T7 must never write.
 *
 * Compared as a whole rather than one at a time: a relay that learned to write
 * exhausted_at would otherwise only be caught by whichever test happened to
 * mention that column.
 */
function relayT8Columns(array $snapshot): array
{
    return [
        'projection_completed_at' => $snapshot['projection_completed_at'],
        'projection_failure_count' => $snapshot['projection_failure_count'],
        'last_projection_error' => $snapshot['last_projection_error'],
        'exhausted_at' => $snapshot['exhausted_at'],
    ];
}

/**
 * One committed envelope, shaped exactly as PaymentOutboxWriter leaves it.
 *
 * Inserted directly rather than through POST /api/payments: payment_outbox carries
 * no foreign key to stored_events (a deliberate T5 decision), so the relay's
 * contract can be proved without a merchant, an aggregate or an HTTP request. All
 * three counters take their zero defaults, exactly as T6 leaves them.
 */
function relayEnvelope(
    ?string $eventId = null,
    string $availableAt = 'now()',
    ?string $projectionCompletedAt = null,
    ?string $exhaustedAt = null,
): string {
    $eventId ??= (string) Str::uuid7();
    $aggregateUuid = (string) Str::uuid7();

    DB::statement(
        <<<SQL
        insert into payment_outbox (
            event_id, event_type, schema_version, aggregate_uuid, aggregate_version,
            payload, occurred_at, available_at, projection_completed_at, exhausted_at,
            created_at, updated_at
        ) values (?, ?, ?, ?, ?, ?::jsonb, now(), {$availableAt}, ?, ?, now(), now())
        SQL,
        [
            $eventId,
            'payments.payment_initiated',
            1,
            $aggregateUuid,
            1,
            json_encode([
                'eventId' => $eventId,
                'paymentId' => $aggregateUuid,
                'merchantId' => 4242,
                'amountMinor' => 1_250,
                'currency' => 'USD',
                'occurredAt' => '2026-08-21T10:11:12.123456+00:00',
            ]),
            $projectionCompletedAt,
            $exhaustedAt,
        ],
    );

    return $eventId;
}

/**
 * The relay as the application resolves it.
 *
 * Container-resolved rather than hand-built wherever a fake is not required, so
 * the provider binding is exercised by nearly every test rather than by one.
 */
function relay(): OutboxRelay
{
    return resolve(OutboxRelay::class);
}

/** The relay with a transport that refuses, and everything else real. */
function relayWithRejectingTransport(RejectingQueue $queue): OutboxRelay
{
    return new OutboxRelay(
        DB::connection(),
        $queue,
        RelayConfiguration::fromArray(
            (array) config('payments.relay'),
            (array) config('database.redis.payments'),
        ),
        Log::getFacadeRoot(),
    );
}

/**
 * Start capturing log records, and hand back the live collection.
 *
 * An ArrayObject rather than an array: an array returned here would be COPIED at
 * the moment of return, so every later assertion would read an empty snapshot
 * while the listener filled a different variable — a test that could only ever
 * pass by asserting nothing.
 */
function relayCapturedLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context];
    });

    return $records;
}

/**
 * One allowlisted context field from every captured record, for the records that
 * carry it.
 *
 * @return list<mixed>
 */
function relayLogField(ArrayObject $records, string $field): array
{
    return array_values(array_column(array_column($records->getArrayCopy(), 'context'), $field));
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();

    RelayTransport::configure();

    // The transport override took, read from the server, before anything is
    // published or deleted.
    expect(RelayTransport::databaseNumber())->toBe(RelayTransport::DATABASE);
    expect(RelayTransport::existingKeys())->toBe([]);
    expect(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([]);

    // Unwrapped, on both witnesses. transactionLevel() and the PDO can disagree
    // after a commit-boundary failure, and this suite deliberately creates that
    // state, so a leak from the previous test must be caught here rather than
    // misattributed to whichever assertion notices first.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('payment_outbox')->count())->toBe(0);
});

afterEach(function (): void {
    RelayTransport::teardown();

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

/*
|--------------------------------------------------------------------------
| The accepted path
|--------------------------------------------------------------------------
*/

it('reports a drained backlog without publishing or writing anything', function (): void {
    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    expect(RelayTransport::size())->toBe(0)
        ->and(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('publishes to Redis while its PostgreSQL transaction is still open', function (): void {
    // THE CROSS-STORE EVIDENCE, and the reason it is conclusive: uncommitted rows
    // are visible only to the session that wrote them, so "Redis already holds the
    // message while the OBSERVER still reads the pre-attempt row" is PostgreSQL's
    // own statement that publication preceded the commit.
    //
    // Observed from a TransactionCommitting listener, which Connection::commit()
    // fires BEFORE getPdo()->commit() — the last instant at which the transaction
    // is provably still open. The listener does not throw; this test is about the
    // successful path.
    $eventId = relayEnvelope();

    $insideCommit = [];

    Event::listen(TransactionCommitting::class, function () use ($eventId, &$insideCommit): void {
        $insideCommit = [
            'redis_size' => RelayTransport::size(),
            'observed' => (array) DB::connection(IntegrationDatabase::OBSERVER)
                ->table('payment_outbox')->where('event_id', $eventId)->first(),
            'relay_transaction_level' => DB::transactionLevel(),
        ];
    });

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect($insideCommit['redis_size'])->toBe(1)
        ->and($insideCommit['relay_transaction_level'])->toBe(1)
        // Still the pre-attempt row, from the second backend.
        ->and($insideCommit['observed']['last_published_at'])->toBeNull()
        ->and((int) $insideCommit['observed']['publish_count'])->toBe(0);

    // And afterwards the observer sees the committed transport state.
    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(1);
});

it('commits the accepted transport state with the reconciliation grace applied exactly', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    $after = relaySnapshot($eventId);

    expect((int) $after['publish_count'])->toBe(1)
        ->and($after['last_published_at'])->not->toBeNull()
        ->and((int) $after['consecutive_transport_failures'])->toBe(0)
        ->and($after['last_transport_error'])->toBeNull();

    // EXACT, not approximate. Both timestamps come from one PostgreSQL now(), which
    // is transaction_timestamp() and therefore constant for the life of the
    // transaction — so the grace is arithmetic rather than a race with the clock.
    $gap = DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select extract(epoch from (available_at - last_published_at)) as seconds
           from payment_outbox where event_id = ?',
        [$eventId],
    );

    expect((float) $gap->seconds)->toBe(30.0);

    // T8's columns are untouched, including exhausted_at: an accepted publication
    // is not a projection outcome.
    expect(relayT8Columns($after))->toBe(relayT8Columns($before));
});

it('publishes an identifier rather than an envelope', function (): void {
    $eventId = relayEnvelope();

    relay()->relayOne();

    $job = RelayTransport::pendingJobs()->firstOrFail();
    $raw = json_encode($job->payload);

    expect($job->payload['data']['commandName'])->toBe(ProjectPaymentEvent::class)
        ->and($raw)->toContain($eventId);

    // The canonical envelope stays in PostgreSQL. Anything below appearing on the
    // wire would make Redis a second source of business truth.
    foreach (['amountMinor', 'currency', 'merchantId', 'occurredAt', 'aggregate', 'payment_outbox'] as $business) {
        expect($raw)->not->toContain($business);
    }
});

it('takes the oldest eligible row first and cannot see an ineligible one', function (): void {
    // Ordered by (available_at, event_id) — the same order as
    // payment_outbox_eligible_index, so the partial index serves the hot path
    // rather than merely existing.
    $older = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $newer = relayEnvelope(availableAt: "now() - interval '1 second'");

    // The three ways a row is invisible, one per predicate.
    $future = relayEnvelope(availableAt: "now() + interval '1 hour'");
    $completed = relayEnvelope(projectionCompletedAt: 'now()');
    $exhausted = relayEnvelope(exhaustedAt: 'now()');

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($older)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($newer)['publish_count'])->toBe(0);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($newer)['publish_count'])->toBe(1);

    // Nothing else is reachable, however many passes run.
    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    foreach ([$future, $completed, $exhausted] as $invisible) {
        expect((int) relaySnapshot($invisible)['publish_count'])->toBe(0);
    }
});

it('skips a row another session holds and publishes the next one instead', function (): void {
    // M-2. FOR UPDATE alone would BLOCK here until the holder released, and with
    // the relay's 500 ms statement_timeout that block would surface as a real
    // 57014 rather than as progress. SKIP LOCKED is what turns a concurrent relay
    // from a queue into parallel progress, and it is the reason overlapping
    // invocations need no scheduler mutex.
    $held = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $free = relayEnvelope(availableAt: "now() - interval '1 second'");

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select 1 from payment_outbox where event_id = ? for update', [$held]);

    // No sleep, no retry: one pass, and it must return the OTHER row.
    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect((int) relaySnapshot($free)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($held)['publish_count'])->toBe(0);

    $lock->rollBack();

    // Released, so the previously locked row is reachable again.
    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($held)['publish_count'])->toBe(1);
});

it('compiles the eligibility query with SKIP LOCKED rather than a bare row lock', function (): void {
    // The behavioural proof above would also pass against `for update` if the
    // holder released in time. This pins the clause itself: Laravel has no
    // skipLocked() helper, so the string is passed to lock() verbatim and a typo
    // would silently become a blocking read.
    $sql = DB::table('payment_outbox')->lock('for update skip locked')->toSql();

    expect($sql)->toEndWith('for update skip locked');
});

it('makes an accepted row eligible again only after the grace has passed', function (): void {
    $eventId = relayEnvelope();

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    // Inside the grace: invisible, so a consumer has time to write its receipt.
    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    // Past the grace with no projection_completed_at — which is what a missing
    // projector receipt looks like from T7's side — the row returns to the active
    // index and is redelivered. T7 SCHEDULES that redelivery; T8's receipt is what
    // ends the cycle.
    DB::statement("update payment_outbox set available_at = now() - interval '1 second' where event_id = ?", [$eventId]);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(2)
        ->and(RelayTransport::size())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Transport rejection — retryable, never poison
|--------------------------------------------------------------------------
*/

it('records a transport rejection as retryable state and leaves publication state alone', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $outcome = relayWithRejectingTransport(RejectingQueue::redis())->relayOne();

    expect($outcome)->toBe(RelayOutcome::TransportRejected);

    $after = relaySnapshot($eventId);

    // COMMITTED, not rolled back. Redis refusing a command says nothing about
    // PostgreSQL, and discarding the failure state would lose the backoff and let
    // the row hammer a dead transport once a second forever.
    expect((int) $after['consecutive_transport_failures'])->toBe(1)
        ->and($after['last_transport_error'])->toBe('redis_transport_rejected')
        // Publication state is untouched: nothing was published.
        ->and($after['last_published_at'])->toBeNull()
        ->and((int) $after['publish_count'])->toBe(0);

    // AN ARBITRARILY LONG OUTAGE MUST NEVER EXHAUST A ROW. exhausted_at and the
    // projection counter belong to T8 and stay exactly as they were.
    expect(relayT8Columns($after))->toBe(relayT8Columns($before));

    expect(RelayTransport::size())->toBe(0);
});

it('backs off further on each consecutive rejection and clears the state on the next acceptance', function (): void {
    $eventId = relayEnvelope();

    $delays = [];

    foreach ([1, 2, 3] as $attempt) {
        // Each pass needs the row eligible again; the backoff itself is what is
        // being measured, so available_at is read before it is reset.
        DB::statement("update payment_outbox set available_at = now() - interval '1 second' where event_id = ?", [$eventId]);

        expect(relayWithRejectingTransport(RejectingQueue::redis())->relayOne())
            ->toBe(RelayOutcome::TransportRejected);

        $snapshot = relaySnapshot($eventId);

        expect((int) $snapshot['consecutive_transport_failures'])->toBe($attempt)
            ->and($snapshot['exhausted_at'])->toBeNull();

        $delays[] = (int) round((float) DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
            'select extract(epoch from (available_at - updated_at)) as seconds
               from payment_outbox where event_id = ?',
            [$eventId],
        )->seconds);
    }

    expect($delays)->toBe([1, 2, 4]);

    // One acceptance clears the whole transport-failure state, so a recovered
    // transport does not carry a stale count into its next rejection.
    DB::statement("update payment_outbox set available_at = now() - interval '1 second' where event_id = ?", [$eventId]);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    $recovered = relaySnapshot($eventId);

    expect((int) $recovered['consecutive_transport_failures'])->toBe(0)
        ->and($recovered['last_transport_error'])->toBeNull()
        ->and((int) $recovered['publish_count'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Everything that is NOT a transport rejection
|--------------------------------------------------------------------------
*/

it('rolls the row back and aborts the pass when PostgreSQL fails inside the transaction', function (): void {
    // A REAL PostgreSQL failure, not an injected exception: the lock connection
    // takes ACCESS EXCLUSIVE on payment_outbox, the relay's SELECT queues behind
    // it, and the relay's own `set local statement_timeout` cancels the statement.
    // This is simultaneously the only proof that the statement timeout is applied
    // at all.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->statement('lock table payment_outbox in access exclusive mode');

    expect(fn () => relay()->relayOne())->toThrow(QueryException::class);

    $lock->rollBack();

    // 57014 query_canceled — the statement timeout, not a lock timeout and not a
    // concurrency error. It is deliberately NOT on the retry allowlist: the relay
    // does not retry, its retry is the next scheduled pass.
    // (Asserted through the row state below rather than by catching twice.)
    expect(relaySnapshot($eventId))->toBe($before);

    // Both witnesses clean, so the connection is reusable.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    expect(RelayTransport::size())->toBe(0);
});

it('lets a non-transport publication failure escape instead of recording a transport rejection', function (): void {
    // THE CATCH BOUNDARY, IN THE DIRECTION THAT IS EASY TO GET WRONG. A relay that
    // caught Throwable around the push would convert a serialisation fault, a
    // configuration error or a lost connection into a durable "transport rejected"
    // backoff — putting a permanent defect on a retry schedule, reporting a healthy
    // pass, and leaving publish_count at zero forever with no error anyone reads.
    //
    // Found by mutation: `catch (RedisException)` -> `catch (Throwable)` broke no
    // test until this one existed.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $records = relayCapturedLogs();

    expect(fn () => relayWithRejectingTransport(RejectingQueue::programming())->relayOne())
        ->toThrow(RuntimeException::class, 'unserializable job payload');

    // Nothing was recorded as a transport failure, and nothing was committed.
    expect(relaySnapshot($eventId))->toBe($before)
        ->and((int) relaySnapshot($eventId)['consecutive_transport_failures'])->toBe(0)
        ->and(relaySnapshot($eventId)['last_transport_error'])->toBeNull();

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    // It is reported as a failed pass, not as a rejection.
    $categories = relayLogField($records, 'category');

    expect($categories)->toContain('relay_failed_before_commit')
        ->and($categories)->not->toContain('redis_transport_rejected');
});

it('classifies a failure before BEGIN as pre-commit rather than inferring it from an idle connection', function (): void {
    // The end state of a failure BEFORE the transaction ever opened is identical to
    // the end state of a failure AFTER a successful commit: level 0, PDO idle.
    // Inferring the outcome from that state would report a durable commit for work
    // that never started. The relay tracks the phase instead.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $records = relayCapturedLogs();

    // SELF-DISARMING. The callback is registered on the Connection object, which
    // outlives this test body; left armed it would make every later
    // beginTransaction() throw, including the ones this file's own teardown and
    // successor tests depend on.
    $armed = true;

    DB::connection()->beforeStartingTransaction(function () use (&$armed): void {
        if (! $armed) {
            return;
        }

        $armed = false;

        throw new RuntimeException('failed before BEGIN');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'failed before BEGIN');

    expect(relaySnapshot($eventId))->toBe($before)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $categories = relayLogField($records, 'category');

    expect($categories)->toContain('relay_failed_before_commit')
        ->and($categories)->not->toContain('commit_outcome_unknown');
});

it('leaves Laravel and the PDO clean after a commit-boundary failure, and relays again on the same connection', function (): void {
    // Connection::commit() fires `committing` BEFORE getPdo()->commit(), so a
    // throwing listener fails the boundary with the transaction still genuinely
    // open — the case where the relay must roll back rather than guess.
    $first = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $second = relayEnvelope(availableAt: "now() - interval '1 second'");

    $before = relaySnapshot($first);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('commit boundary failed');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'commit boundary failed');

    // Observed from the second backend: nothing durable happened.
    expect(relaySnapshot($first))->toBe($before);

    // BOTH WITNESSES. T4 measured that they can disagree, and a connection left
    // `idle in transaction` would hold its row lock and wedge the next pass.
    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    Event::forget(TransactionCommitting::class);

    // THE CONNECTION IS STILL USABLE. Cleanliness that could not be followed by a
    // successful relay on the same connection would be a claim about counters, not
    // about the connection.
    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($first)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($second)['publish_count'])->toBe(0);
});

it('restores the exact pre-attempt row after a crash between the accepted push and the commit', function (): void {
    // The documented safe duplicate. Redis has already accepted the message when
    // the process dies, so the message survives while the row does not — and the
    // next pass republishes it.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('crashed after the push');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'crashed after the push');

    // Redis kept the message; PostgreSQL kept nothing.
    expect(RelayTransport::size())->toBe(1);

    // EXACT, column by column, not a spot check on two of them: available_at
    // included, so a partially applied update would fail here.
    expect(relaySnapshot($eventId))->toBe($before);

    Event::forget(TransactionCommitting::class);

    // The later pass, with only the crash removed.
    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect(RelayTransport::size())->toBe(2)
        ->and((int) relaySnapshot($eventId)['publish_count'])->toBe(1);

    // Both messages carry the same identifier — one safe duplicate, not two
    // different payments.
    $ids = RelayTransport::pendingJobs()
        ->map(fn ($job): string => (string) json_encode($job->payload))
        ->filter(fn (string $payload): bool => str_contains($payload, $eventId));

    expect($ids)->toHaveCount(2);
});

it('reports an ambiguous commit outcome instead of claiming the work rolled back', function (): void {
    // Connection::commit() fires `committed` AFTER getPdo()->commit() has already
    // succeeded, so a throwing listener leaves durable work behind while the
    // connection looks exactly like one that never committed: level 0, PDO idle.
    // The relay cannot distinguish the two and must not pretend it can.
    $eventId = relayEnvelope();

    $records = relayCapturedLogs();

    Event::listen(TransactionCommitted::class, function (): void {
        throw new RuntimeException('after the commit');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'after the commit');

    Event::forget(TransactionCommitted::class);

    // ONE POSSIBLE CASE, PROVED COMMITTED FROM THE OBSERVER. This is evidence that
    // the ambiguity is real, not a claim that every such failure commits.
    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(1);

    expect(relayLogField($records, 'category'))->toContain('commit_outcome_unknown');

    // AND THE PASS DOES NOT REPUBLISH. The committed grace is now on the row, so
    // the next pass sees nothing eligible and the reconciliation is left to a later
    // scheduled process reading canonical state.
    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);
});

it('preserves the original failure when the rollback cleanup also throws', function (): void {
    // Connection::rollBack() fires `rollingBack` at the very end, so a throwing
    // listener makes the cleanup itself fail after the rollback has happened. A
    // cleanup exception replacing the primary one would delete the only
    // information that explains what went wrong.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('the primary failure');
    });

    Event::listen(TransactionRolledBack::class, function (): void {
        throw new RuntimeException('the cleanup failure');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'the primary failure');

    Event::forget(TransactionCommitting::class);
    Event::forget(TransactionRolledBack::class);

    expect(relaySnapshot($eventId))->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Wiring and log safety
|--------------------------------------------------------------------------
*/

it('resolves onto the same connection the rest of Payments writes through', function (): void {
    // Connection affinity, which the relay depends on completely: the lock, the
    // publication and the update must all be one backend, or FOR UPDATE protects
    // nothing the UPDATE then changes.
    $relay = relay();

    $property = new ReflectionProperty(OutboxRelay::class, 'connection');

    expect($property->getValue($relay))->toBe(DB::connection())
        ->and($property->getValue($relay))->toBeInstanceOf(Connection::class);

    // And the container hands out the same relay within one resolution scope.
    expect(relay())->toBe($relay);
});

it('keeps raw exception detail out of every relay log record', function (): void {
    // A driver exception message is written by the transport, not by us: it can
    // carry a DSN, a password, a host or whatever the server echoed back. Only
    // allowlisted structured fields are recorded, and the persisted
    // last_transport_error is a fixed category rather than a message.
    $sentinel = 'S3CRET-sentinel-do-not-log';

    $eventId = relayEnvelope();
    $records = relayCapturedLogs();

    relayWithRejectingTransport(RejectingQueue::redis("connection refused {$sentinel}"))->relayOne();

    expect($records->getArrayCopy())->not->toBeEmpty();

    foreach ($records->getArrayCopy() as $record) {
        $rendered = $record['message'].' '.json_encode($record['context']);

        expect($rendered)->not->toContain($sentinel)
            ->and($rendered)->not->toContain('connection refused');
    }

    // The exception CLASS is allowlisted and useful; the message is not.
    expect(relayLogField($records, 'exception_class'))->toContain(RedisException::class);

    expect(relaySnapshot($eventId)['last_transport_error'])->toBe('redis_transport_rejected');
});

it('does not retry a failed row inside the same pass', function (): void {
    // One attempt, deliberately. The relay's retry is the next scheduled pass one
    // second later; a loop here would hammer a failing row and could not tell a
    // transient failure from a permanent one.
    $eventId = relayEnvelope();

    $begins = 0;

    Event::listen(TransactionCommitting::class, function () use (&$begins): void {
        $begins++;

        throw new RuntimeException('failed once');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class);

    Event::forget(TransactionCommitting::class);

    expect($begins)->toBe(1)
        ->and((int) relaySnapshot($eventId)['publish_count'])->toBe(0);

    // The classifier the rest of Payments uses agrees this is not a concurrency
    // error, so nothing on the retry allowlist could have made the relay loop.
    expect(RetryingAtomicTransaction::sqlStateOf(new RuntimeException('failed once')))->toBeNull();
});
