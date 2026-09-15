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
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\ProjectPaymentEvent;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayOutcome;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;
use Modules\Payments\Tests\Integration\RejectingQueue;
use Modules\Payments\Tests\Support\RelayTransport;
use RedisException;

function relaySnapshot(string $eventId): array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')
        ->where('event_id', $eventId)
        ->first();

    expect($row)->not->toBeNull("no committed payment_outbox row for {$eventId}");

    return (array) $row;
}

function relayT8Columns(array $snapshot): array
{
    return [
        'projection_completed_at' => $snapshot['projection_completed_at'],
        'projection_failure_count' => $snapshot['projection_failure_count'],
        'last_projection_error' => $snapshot['last_projection_error'],
        'exhausted_at' => $snapshot['exhausted_at'],
    ];
}

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

function relay(): OutboxRelay
{
    return resolve(OutboxRelay::class);
}

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
        new ProjectionGate(DB::connection()),
    );
}

/** An ArrayObject, because a returned array would be a copy the listener never fills. */
function relayCapturedLogs(): ArrayObject
{
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = ['level' => $logged->level, 'message' => $logged->message, 'context' => $logged->context];
    });

    return $records;
}

/** @return list<mixed> */
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

    expect(RelayTransport::databaseNumber())->toBe(RelayTransport::DATABASE);
    expect(RelayTransport::existingKeys())->toBe([]);
    expect(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([]);

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

it('reports a drained backlog without publishing or writing anything', function (): void {
    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    expect(RelayTransport::size())->toBe(0)
        ->and(RelayTransport::existingKeys(RelayTransport::clientPrefix()))->toBe([])
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('publishes to Redis while its PostgreSQL transaction is still open', function (): void {
    // Redis holding the message while the observer still reads the old row proves publication preceded COMMIT.
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
        ->and($insideCommit['observed']['last_published_at'])->toBeNull()
        ->and((int) $insideCommit['observed']['publish_count'])->toBe(0);

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

    // Exact: both timestamps come from one transaction-constant now().
    $gap = DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
        'select extract(epoch from (available_at - last_published_at)) as seconds
           from payment_outbox where event_id = ?',
        [$eventId],
    );

    expect((float) $gap->seconds)->toBe(30.0);

    expect(relayT8Columns($after))->toBe(relayT8Columns($before));
});

it('publishes an identifier rather than an envelope', function (): void {
    $eventId = relayEnvelope();

    relay()->relayOne();

    $job = RelayTransport::pendingJobs()->firstOrFail();
    $raw = json_encode($job->payload);

    expect($job->payload['data']['commandName'])->toBe(ProjectPaymentEvent::class)
        ->and($raw)->toContain($eventId);

    foreach (['amountMinor', 'currency', 'merchantId', 'occurredAt', 'aggregate', 'payment_outbox'] as $business) {
        expect($raw)->not->toContain($business);
    }
});

it('takes the oldest eligible row first and cannot see an ineligible one', function (): void {
    $older = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $newer = relayEnvelope(availableAt: "now() - interval '1 second'");

    $future = relayEnvelope(availableAt: "now() + interval '1 hour'");
    $completed = relayEnvelope(projectionCompletedAt: 'now()');
    $exhausted = relayEnvelope(exhaustedAt: 'now()');

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($older)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($newer)['publish_count'])->toBe(0);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($newer)['publish_count'])->toBe(1);

    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    foreach ([$future, $completed, $exhausted] as $invisible) {
        expect((int) relaySnapshot($invisible)['publish_count'])->toBe(0);
    }
});

it('skips a row another session holds and publishes the next one instead', function (): void {
    // FOR UPDATE alone would block into a statement timeout; SKIP LOCKED lets overlapping passes progress.
    $held = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $free = relayEnvelope(availableAt: "now() - interval '1 second'");

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->select('select 1 from payment_outbox where event_id = ? for update', [$held]);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect((int) relaySnapshot($free)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($held)['publish_count'])->toBe(0);

    $lock->rollBack();

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($held)['publish_count'])->toBe(1);
});

it('makes an accepted row eligible again only after the grace has passed', function (): void {
    $eventId = relayEnvelope();

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);

    DB::statement("update payment_outbox set available_at = now() - interval '1 second' where event_id = ?", [$eventId]);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(2)
        ->and(RelayTransport::size())->toBe(2);
});

it('records a transport rejection as retryable state and leaves publication state alone', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $outcome = relayWithRejectingTransport(RejectingQueue::redis())->relayOne();

    expect($outcome)->toBe(RelayOutcome::TransportRejected);

    $after = relaySnapshot($eventId);

    // Committed, not rolled back: losing the backoff would hammer a dead transport every pass.
    expect((int) $after['consecutive_transport_failures'])->toBe(1)
        ->and($after['last_transport_error'])->toBe('redis_transport_rejected')
        ->and($after['last_published_at'])->toBeNull()
        ->and((int) $after['publish_count'])->toBe(0);

    expect(relayT8Columns($after))->toBe(relayT8Columns($before));

    expect(RelayTransport::size())->toBe(0);
});

it('backs off further on each consecutive rejection and clears the state on the next acceptance', function (): void {
    $eventId = relayEnvelope();

    $delays = [];

    foreach ([1, 2, 3] as $attempt) {
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

    DB::statement("update payment_outbox set available_at = now() - interval '1 second' where event_id = ?", [$eventId]);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    $recovered = relaySnapshot($eventId);

    expect((int) $recovered['consecutive_transport_failures'])->toBe(0)
        ->and($recovered['last_transport_error'])->toBeNull()
        ->and((int) $recovered['publish_count'])->toBe(1);
});

it('rolls the row back and aborts the pass when PostgreSQL fails inside the transaction', function (): void {
    // The relay's own statement_timeout cancels its SELECT behind ACCESS EXCLUSIVE, proving the timeout is applied.
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $lock = DB::connection(IntegrationDatabase::LOCK);
    $lock->beginTransaction();
    $lock->statement('lock table payment_outbox in access exclusive mode');

    expect(fn () => relay()->relayOne())->toThrow(QueryException::class);

    $lock->rollBack();

    expect(relaySnapshot($eventId))->toBe($before);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    expect(RelayTransport::size())->toBe(0);
});

it('lets a non-transport publication failure escape instead of recording a transport rejection', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $records = relayCapturedLogs();

    expect(fn () => relayWithRejectingTransport(RejectingQueue::programming())->relayOne())
        ->toThrow(RuntimeException::class, 'unserializable job payload');

    expect(relaySnapshot($eventId))->toBe($before)
        ->and((int) relaySnapshot($eventId)['consecutive_transport_failures'])->toBe(0)
        ->and(relaySnapshot($eventId)['last_transport_error'])->toBeNull();

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    $categories = relayLogField($records, 'category');

    expect($categories)->toContain('relay_failed_before_commit')
        ->and($categories)->not->toContain('redis_transport_rejected');
});

it('classifies a failure before BEGIN as pre-commit rather than inferring it from an idle connection', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    $records = relayCapturedLogs();

    // Self-disarming: the callback stays registered on the shared Connection object.
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
    // `committing` fires before the PDO commit, so the transaction is still genuinely open.
    $first = relayEnvelope(availableAt: "now() - interval '10 seconds'");
    $second = relayEnvelope(availableAt: "now() - interval '1 second'");

    $before = relaySnapshot($first);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('commit boundary failed');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'commit boundary failed');

    expect(relaySnapshot($first))->toBe($before);

    expect(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    Event::forget(TransactionCommitting::class);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);
    expect((int) relaySnapshot($first)['publish_count'])->toBe(1)
        ->and((int) relaySnapshot($second)['publish_count'])->toBe(0);
});

it('restores the exact pre-attempt row after a crash between the accepted push and the commit', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('crashed after the push');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'crashed after the push');

    expect(RelayTransport::size())->toBe(1);

    expect(relaySnapshot($eventId))->toBe($before);

    Event::forget(TransactionCommitting::class);

    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect(RelayTransport::size())->toBe(2)
        ->and((int) relaySnapshot($eventId)['publish_count'])->toBe(1);

    $ids = RelayTransport::pendingJobs()
        ->map(fn ($job): string => (string) json_encode($job->payload))
        ->filter(fn (string $payload): bool => str_contains($payload, $eventId));

    expect($ids)->toHaveCount(2);
});

it('reports an ambiguous commit outcome instead of claiming the work rolled back', function (): void {
    // `committed` fires after the PDO commit, so durable work exists while the connection looks rolled back.
    $eventId = relayEnvelope();

    $records = relayCapturedLogs();

    Event::listen(TransactionCommitted::class, function (): void {
        throw new RuntimeException('after the commit');
    });

    expect(fn () => relay()->relayOne())->toThrow(RuntimeException::class, 'after the commit');

    Event::forget(TransactionCommitted::class);

    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(1);

    expect(relayLogField($records, 'category'))->toContain('commit_outcome_unknown');

    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);
});

it('preserves the original failure when the rollback cleanup also throws', function (): void {
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

it('resolves onto the same connection the rest of Payments writes through', function (): void {
    $relay = relay();

    $property = new ReflectionProperty(OutboxRelay::class, 'connection');

    expect($property->getValue($relay))->toBe(DB::connection())
        ->and($property->getValue($relay))->toBeInstanceOf(Connection::class);

    expect(relay())->toBe($relay);
});

it('keeps raw exception detail out of every relay log record', function (): void {
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

    expect(relayLogField($records, 'exception_class'))->toContain(RedisException::class);

    expect(relaySnapshot($eventId)['last_transport_error'])->toBe('redis_transport_rejected');
});

it('does not retry a failed row inside the same pass', function (): void {
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

    expect(RetryingAtomicTransaction::sqlStateOf(new RuntimeException('failed once')))->toBeNull();
});

function relayCloseGate(): void
{
    DB::table('payment_projection_controls')->where('projection', 'payment_details')->update([
        'state' => 'rebuilding',
        'owner_token' => (string) Str::uuid7(),
        'owner_pid' => 999_999,
        'started_at' => now(),
        'updated_at' => now(),
    ]);
}

function relayReopenGate(): void
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

it('reports a closed gate without selecting a row, publishing or writing anything', function (): void {
    $eventId = relayEnvelope();
    $before = relaySnapshot($eventId);

    relayCloseGate();

    expect(relay()->relayOne())->toBe(RelayOutcome::GateClosed);

    expect(relaySnapshot($eventId))->toBe($before)
        ->and(RelayTransport::size())->toBe(0)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

    relayReopenGate();
});

it('is a distinct outcome from a drained backlog, and the pass log agrees', function (): void {
    relayCloseGate();

    expect(relay()->relayOne())->toBe(RelayOutcome::GateClosed)
        ->and(RelayOutcome::GateClosed)->not->toBe(RelayOutcome::NothingEligible);

    relayReopenGate();

    expect(relay()->relayOne())->toBe(RelayOutcome::NothingEligible);
});

it('holds the gate lock on its own connection while the pass is refused by the control row', function (): void {
    relayEnvelope();
    relayCloseGate();

    $lockDuringCommit = [];

    Event::listen(TransactionCommitting::class, function () use (&$lockDuringCommit): void {
        $lockDuringCommit = ProjectionGateLocks::onKey(
            ProjectionGate::CLASS_ID,
            ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        );
    });

    expect(relay()->relayOne())->toBe(RelayOutcome::GateClosed);

    Event::forget(TransactionCommitting::class);

    expect($lockDuringCommit)->toHaveCount(1)
        ->and($lockDuringCommit[0]['mode'])->toBe('ShareLock')
        ->and($lockDuringCommit[0]['granted'])->toBeTrue();

    $relayConnection = new ReflectionProperty(OutboxRelay::class, 'connection')->getValue(relay());
    expect($relayConnection)->toBe(DB::connection());

    expect(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))
        ->toBe([]);

    relayReopenGate();
});

it('republishes the deferred event once the gate reopens, on the ordinary reconciliation path', function (): void {
    $eventId = relayEnvelope();

    relayCloseGate();
    expect(relay()->relayOne())->toBe(RelayOutcome::GateClosed);

    relayReopenGate();
    expect(relay()->relayOne())->toBe(RelayOutcome::Published);

    expect((int) relaySnapshot($eventId)['publish_count'])->toBe(1)
        ->and(RelayTransport::size())->toBe(1);
});
