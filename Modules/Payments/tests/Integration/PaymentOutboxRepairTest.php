<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Repair\OutboxRepair;
use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Application\Repair\RepairOutcome;
use Modules\Payments\Application\Repair\RepairReason;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Repair\DatabaseOutboxRepair;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;
use Modules\Payments\Tests\Support\FailingLogger;

/*
|--------------------------------------------------------------------------
| Targeted outbox repair — `payments:outbox:repair`
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE BECAUSE THE SUBJECT IS A REAL TRANSACTION. Repair holds
| a lock on canonical history across several statements and commits for real; under
| RefreshDatabase every commit becomes a savepoint and the second-connection
| evidence below — including the serialisation of two concurrent repairs — would be
| vacuous.
|
| THE COMMAND IS INVOKED THROUGH Artisan, so the signature, the option, the
| container bindings and the registration are all exercised. A test that new-ed the
| class would pass with the command unregistered, which is the failure an operator
| would meet.
|
*/

/**
 * One canonical event, with whichever derived rows the scenario wants.
 *
 * @return array{event_id: string, payment_id: string, merchant_id: int}
 */
function repairSeed(
    ?int $merchantId = null,
    bool $withEnvelope = true,
    bool $withReceipt = false,
    array $payloadOverrides = [],
    array $canonicalOverrides = [],
    array $envelopeOverrides = [],
): array {
    $merchantId ??= Merchant::factory()->create()->getKey();
    $eventId = (string) Str::uuid7();
    $paymentId = (string) Str::uuid7();
    $occurredAt = '2026-08-21T10:11:12.123456+00:00';

    $payload = array_merge([
        'eventId' => $eventId,
        'paymentId' => $paymentId,
        'merchantId' => $merchantId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => $occurredAt,
    ], $payloadOverrides);

    DB::table('stored_events')->insert(array_merge([
        'aggregate_uuid' => $paymentId,
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => PaymentInitiated::ALIAS,
        'event_properties' => json_encode($payload),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ], $canonicalOverrides));

    if ($withEnvelope) {
        DB::table('payment_outbox')->insert(array_merge([
            'event_id' => $eventId,
            'event_type' => PaymentInitiated::ALIAS,
            'schema_version' => 1,
            'aggregate_uuid' => $paymentId,
            'aggregate_version' => 1,
            'payload' => json_encode($payload),
            'occurred_at' => $occurredAt,
            // Deliberately far in the future and behind transport history, so a
            // repair that makes the row eligible is observable and one that must
            // not is too.
            'available_at' => '2099-01-01T00:00:00+00:00',
            'publish_count' => 3,
            'last_published_at' => '2026-08-21T10:00:00+00:00',
            'consecutive_transport_failures' => 2,
            'last_transport_error' => 'redis_transport_rejected',
            'projection_failure_count' => 4,
            'projection_failure_generation' => 2,
            'last_projection_error' => 'projection_payload_malformed',
            'created_at' => '2026-08-21T09:00:00+00:00',
            'updated_at' => '2026-08-21T09:00:00+00:00',
        ], $envelopeOverrides));
    }

    if ($withReceipt) {
        DB::table('payment_projector_receipts')->insert([
            'projector_name' => PaymentProjectionConsumer::PROJECTOR_NAME,
            'event_id' => $eventId,
            'created_at' => '2026-08-21T10:30:00.500000+00:00',
        ]);
    }

    return ['event_id' => $eventId, 'payment_id' => $paymentId, 'merchant_id' => $merchantId];
}

/** The repair as an operator runs it: exit code plus the one JSON object it printed. */
function repairRun(string $eventId, bool $quiesced = true): array
{
    $options = ['eventId' => $eventId];

    if ($quiesced) {
        $options['--delivery-quiesced'] = true;
    }

    $exitCode = Artisan::call('payments:outbox:repair', $options);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $decoded];
}

/** The committed envelope, read from the observer so only durable state is seen. */
function repairEnvelope(string $eventId): ?array
{
    $row = DB::connection(IntegrationDatabase::OBSERVER)
        ->table('payment_outbox')->where('event_id', $eventId)->first();

    return $row === null ? null : (array) $row;
}

/** Everything repair must never touch, as one comparable snapshot. */
function repairUntouchedState(): array
{
    $observer = DB::connection(IntegrationDatabase::OBSERVER);

    return [
        'stored_events' => $observer->table('stored_events')->orderBy('id')
            ->get(['id', 'aggregate_uuid', 'aggregate_version', 'event_class', 'event_properties'])->toArray(),
        'receipts' => $observer->table('payment_projector_receipts')
            ->orderBy('event_id')->get()->toArray(),
        'details' => $observer->table('payment_details')->orderBy('payment_id')->get()->toArray(),
        'idempotency' => $observer->table('payment_idempotency_receipts')
            ->orderBy('payment_id')->get()->toArray(),
    ];
}

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
    $this->controlBefore = (array) DB::table('payment_projection_controls')->first();
});

afterEach(function (): void {
    DB::statement('set lock_timeout = default');

    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    DB::table('payment_projection_controls')->updateOrInsert(
        ['projection' => ProjectionGate::PAYMENT_DETAILS], $this->controlBefore,
    );
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

it('refuses a closed maintenance gate before canonical locking without changing any durable state', function (string $closure): void {
    $seeded = repairSeed(envelopeOverrides: ['event_type' => 'payments.drifted']);
    $envelope = repairEnvelope($seeded['event_id']);
    $untouched = repairUntouchedState();

    if ($closure === 'lock') {
        DB::connection(IntegrationDatabase::LOCK)->select('select pg_advisory_lock(?, ?)', [
            ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID,
        ]);
    } elseif ($closure === 'row') {
        DB::table('payment_projection_controls')->update([
            'state' => 'rebuilding', 'owner_token' => (string) Str::uuid7(),
            'owner_pid' => ProjectionGateLocks::pid(IntegrationDatabase::LOCK),
            'started_at' => now(), 'cleared_at' => now(),
        ]);
    } else {
        DB::table('payment_projection_controls')->delete();
    }

    $control = DB::table('payment_projection_controls')->first();
    DB::enableQueryLog();
    DB::flushQueryLog();
    [$exitCode, $result] = repairRun($seeded['event_id']);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe('rebuild_in_progress')
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value)
        ->and($result['changed_fields'])->toBe([])
        ->and($result['delivery_quiesced'])->toBeTrue()
        ->and(repairEnvelope($seeded['event_id']))->toBe($envelope)
        ->and(repairUntouchedState())->toEqual($untouched)
        ->and(DB::table('payment_projection_controls')->first())->toEqual($control)
        ->and(DB::transactionLevel())->toBe(0);

    foreach ($queries as $query) {
        expect($query)->not->toMatch('/\b(stored_events|payment_outbox|payment_details|payment_projector_receipts)\b/i');
    }

    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    DB::table('payment_projection_controls')->updateOrInsert(
        ['projection' => ProjectionGate::PAYMENT_DETAILS], $this->controlBefore,
    );
    [$reopened, $result] = repairRun($seeded['event_id']);
    expect($reopened)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_REPAIRED);
})->with(['exclusive lock before claim' => 'lock', 'abandoned rebuilding row' => 'row', 'missing control row' => 'missing']);

it('holds the shared gate on the repair write backend until its commit', function (): void {
    $seeded = repairSeed(withEnvelope: false);
    $pid = ProjectionGateLocks::pid(DB::getDefaultConnection());
    expect($pid)->not->toBe(ProjectionGateLocks::pid(IntegrationDatabase::LOCK));
    $observed = [];

    DB::listen(function (QueryExecuted $query) use (&$observed, $pid): void {
        if ($query->connection !== DB::connection() || ! str_starts_with(strtolower(ltrim($query->sql)), 'insert into payment_outbox')) {
            return;
        }

        $observed['locks'] = array_values(array_filter(
            ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID),
            static fn (array $lock): bool => $lock['pid'] === $pid && $lock['granted']
                && $lock['mode'] === 'ShareLock' && $lock['objsubid'] === 2,
        ));
        $observed['isolation'] = DB::selectOne("select current_setting('transaction_isolation') as value")->value;
        $observed['exclusive'] = (int) DB::connection(IntegrationDatabase::LOCK)->selectOne(
            'select pg_try_advisory_lock(?, ?)::int as acquired',
            [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
        )->acquired;
    });

    [$exitCode] = repairRun($seeded['event_id']);
    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($observed['locks'])->toHaveCount(1)
        ->and($observed['isolation'])->toBe('read committed')
        ->and($observed['exclusive'])->toBe(0)
        ->and(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))->toBe([]);

    expect((int) DB::connection(IntegrationDatabase::LOCK)->selectOne(
        'select pg_try_advisory_lock(?, ?)::int as acquired',
        [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
    )->acquired)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Refusals, none of which may write
|--------------------------------------------------------------------------
*/

it('refuses without the delivery-quiescence acknowledgement, before touching the database', function (): void {
    $seeded = repairSeed(withEnvelope: false);

    [$exitCode, $result] = repairRun($seeded['event_id'], quiesced: false);

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_REFUSED)
        ->and($result['reason'])->toBe(RepairReason::DeliveryNotQuiesced->value)
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value)
        ->and($result['delivery_quiesced'])->toBeFalse()
        ->and(repairEnvelope($seeded['event_id']))->toBeNull();
});

it('refuses a malformed event id without echoing it back', function (): void {
    [$exitCode, $result] = repairRun('not-a-uuid');

    // NEVER ECHOED. Whatever was typed is operator input, and the refusal is
    // diagnosable without repeating it into stdout or the audit.
    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RepairReason::MalformedEventId->value)
        ->and($result['event_id'])->toBeNull()
        ->and(json_encode($result))->not->toContain('not-a-uuid');
});

it('returns safe JSON and exit 2 when the repair event argument is missing', function (): void {
    $exitCode = Artisan::call('payments:outbox:repair', ['--delivery-quiesced' => true]);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe('invalid_invocation')
        ->and($result['mutation_state'])->toBe('none')
        ->and($result['event_id'])->toBeNull()
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('refuses when no canonical event carries that durable id', function (): void {
    repairSeed();

    [$exitCode, $result] = repairRun((string) Str::uuid7());

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RepairReason::CanonicalEventAbsent->value)
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value);
});

it('refuses an ambiguous canonical history rather than choosing a match', function (): void {
    // TWO HISTORIES, ONE DURABLE ID. Reconstructing from either would produce an
    // envelope that looks repaired and describes the wrong payment.
    $merchantId = Merchant::factory()->create()->getKey();
    $shared = (string) Str::uuid7();

    repairSeed(merchantId: $merchantId, withEnvelope: false, payloadOverrides: ['eventId' => $shared]);
    repairSeed(merchantId: $merchantId, withEnvelope: false, payloadOverrides: ['eventId' => $shared]);

    [$exitCode, $result] = repairRun($shared);

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RepairReason::CanonicalEventAmbiguous->value)
        ->and(repairEnvelope($shared))->toBeNull();
});

it('refuses canonical history it cannot decode', function (string $reason, array $canonical, array $payload): void {
    $seeded = repairSeed(
        withEnvelope: false,
        payloadOverrides: $payload,
        canonicalOverrides: $canonical,
    );

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe($reason)
        ->and(repairEnvelope($seeded['event_id']))->toBeNull();
})->with([
    'unsupported schema version' => [
        RepairReason::CanonicalSchemaVersionUnsupported->value, ['event_version' => 2], [],
    ],
    'unsupported aggregate version' => [
        RepairReason::CanonicalAggregateVersionUnsupported->value, ['aggregate_version' => 4], [],
    ],
    'malformed payload' => [
        RepairReason::CanonicalPayloadMalformed->value, [], ['amountMinor' => 'twelve-fifty'],
    ],
]);

it('refuses an alias it does not decode instead of reporting absent history', function (): void {
    // SCOPED BY PREFIX, so a future Payments event is REFUSED rather than reported
    // as history that does not exist — which would send an operator to look for a
    // deleted event.
    $seeded = repairSeed(withEnvelope: false, canonicalOverrides: ['event_class' => 'payments.payment_settled']);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RepairReason::CanonicalAliasUnsupported->value);
});

it('refuses when another envelope already holds the aggregate identity', function (): void {
    // REFUSED, NEVER RESOLVED. payment_outbox is unique on
    // (aggregate_uuid, aggregate_version), so writing this row would mean removing
    // or moving that one — a second corruption in the name of repairing the first.
    $seeded = repairSeed(withEnvelope: false);
    $squatter = (string) Str::uuid7();

    DB::table('payment_outbox')->insert([
        'event_id' => $squatter,
        'event_type' => PaymentInitiated::ALIAS,
        'schema_version' => 1,
        'aggregate_uuid' => $seeded['payment_id'],
        'aggregate_version' => 1,
        'payload' => json_encode(['squatter' => true]),
        'occurred_at' => now(),
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['reason'])->toBe(RepairReason::AggregateIdentityConflict->value)
        ->and(repairEnvelope($seeded['event_id']))->toBeNull()
        // The conflicting row is still there, untouched.
        ->and(repairEnvelope($squatter))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The mutation matrix
|--------------------------------------------------------------------------
*/

it('reconstructs a missing envelope from canonical history and says the history was reinitialised', function (): void {
    $seeded = repairSeed(withEnvelope: false);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    $envelope = repairEnvelope($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_CREATED)
        ->and($result['mutation_state'])->toBe(RepairMutationState::Created->value)
        // DELETED DELIVERY HISTORY CANNOT BE RECONSTRUCTED FROM A STORED EVENT,
        // including a possible former exhaustion, so the claim is reinitialised
        // rather than restored.
        ->and($result['history_reinitialized'])->toBeTrue()
        ->and($envelope['event_type'])->toBe(PaymentInitiated::ALIAS)
        ->and($envelope['aggregate_uuid'])->toBe($seeded['payment_id'])
        ->and((int) $envelope['aggregate_version'])->toBe(1)
        ->and((int) $envelope['publish_count'])->toBe(0)
        ->and((int) $envelope['projection_failure_count'])->toBe(0)
        ->and((int) $envelope['projection_failure_generation'])->toBe(0)
        ->and($envelope['last_published_at'])->toBeNull()
        ->and($envelope['exhausted_at'])->toBeNull()
        // No named receipt, so the row is inserted immediately eligible.
        ->and($envelope['projection_completed_at'])->toBeNull()
        // CANONICAL EVENT TIME IS RETAINED while the operational timestamps are
        // repair time.
        ->and(CarbonImmutable::parse($envelope['occurred_at'])->format(PaymentInitiated::OCCURRED_AT_FORMAT))
        ->toBe('2026-08-21T10:11:12.123456+00:00');
});

it('derives the completion marker from the named receipt when it reconstructs', function (): void {
    $seeded = repairSeed(withEnvelope: false, withReceipt: true);

    [$exitCode] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and(CarbonImmutable::parse(repairEnvelope($seeded['event_id'])['projection_completed_at'])
            ->format(PaymentInitiated::OCCURRED_AT_FORMAT))
        ->toBe('2026-08-21T10:30:00.500000+00:00');
});

it('does not accept another projector receipt as completion evidence', function (): void {
    $seeded = repairSeed(withEnvelope: false);

    DB::table('payment_projector_receipts')->insert([
        'projector_name' => 'some_other_projection',
        'event_id' => $seeded['event_id'],
        'created_at' => now(),
    ]);

    [$exitCode] = repairRun($seeded['event_id']);

    // A receipt owned by another projector says nothing about whether
    // payment_details was built, and a marker stamped from it would retire an
    // envelope whose work was never done.
    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and(repairEnvelope($seeded['event_id'])['projection_completed_at'])->toBeNull();
});

it('restores drifted canonical columns while preserving every history column', function (): void {
    $seeded = repairSeed(envelopeOverrides: [
        'event_type' => 'payments.payment_settled',
        'aggregate_version' => 9,
        'occurred_at' => '2020-01-01T00:00:00.000000+00:00',
        'payload' => json_encode(['tampered' => true]),
    ]);

    $before = repairEnvelope($seeded['event_id']);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    $after = repairEnvelope($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_REPAIRED)
        ->and($result['history_reinitialized'])->toBeFalse()
        ->and($result['changed_fields'])
        ->toBe(['event_type', 'aggregate_version', 'occurred_at', 'payload', 'available_at'])
        // RESTORED from canonical history.
        ->and($after['event_type'])->toBe(PaymentInitiated::ALIAS)
        ->and((int) $after['aggregate_version'])->toBe(1)
        // PRESERVED: transport history, the poison budget, the generation fence.
        ->and((int) $after['publish_count'])->toBe(3)
        ->and($after['last_published_at'])->toBe($before['last_published_at'])
        ->and((int) $after['consecutive_transport_failures'])->toBe(2)
        ->and($after['last_transport_error'])->toBe('redis_transport_rejected')
        ->and((int) $after['projection_failure_count'])->toBe(4)
        ->and((int) $after['projection_failure_generation'])->toBe(2)
        ->and($after['last_projection_error'])->toBe('projection_payload_malformed')
        ->and($after['created_at'])->toBe($before['created_at'])
        ->and($after['updated_at'])->not->toBe($before['updated_at']);
});

it('keeps an exhausted envelope exhausted and unavailable, leaving retry the only way back', function (): void {
    // THE POISON BOUNDARY. Repair corrects what the envelope SAYS;
    // payments:outbox:retry remains the only path that decides an exhausted
    // envelope may be delivered again.
    $seeded = repairSeed(envelopeOverrides: [
        'aggregate_version' => 9,
        'exhausted_at' => '2026-08-21T11:00:00+00:00',
    ]);

    $before = repairEnvelope($seeded['event_id']);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    $after = repairEnvelope($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['changed_fields'])->not->toContain('available_at')
        ->and($after['exhausted_at'])->toBe($before['exhausted_at'])
        ->and($after['available_at'])->toBe($before['available_at'])
        ->and((int) $after['aggregate_version'])->toBe(1);
});

it('leaves a completed envelope where the marker already excludes it', function (): void {
    $seeded = repairSeed(withReceipt: true, envelopeOverrides: [
        'aggregate_version' => 9,
        'projection_completed_at' => '2026-08-21T10:30:00.500000+00:00',
    ]);

    $before = repairEnvelope($seeded['event_id']);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['changed_fields'])->not->toContain('available_at')
        ->and($result['changed_fields'])->not->toContain('projection_completed_at')
        ->and(repairEnvelope($seeded['event_id'])['available_at'])->toBe($before['available_at']);
});

it('fills a missing completion marker from the named receipt, once', function (): void {
    $seeded = repairSeed(withReceipt: true);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['changed_fields'])->toBe(['projection_completed_at'])
        // No availability refresh: the named receipt says the projection is done.
        ->and($result['changed_fields'])->not->toContain('available_at');

    $afterFirst = repairEnvelope($seeded['event_id']);

    // AND IT STAYS FILLED. With a receipt and a marker both present the marker is
    // PRESERVED, not restamped: its timestamp is the historical fact of when the
    // projection completed, and rewriting it on every repair would move that fact.
    [, $second] = repairRun($seeded['event_id']);

    expect($second['outcome'])->toBe(RepairOutcome::OUTCOME_UNCHANGED)
        ->and($second['changed_fields'])->toBe([])
        ->and(repairEnvelope($seeded['event_id']))->toBe($afterFirst);
});

it('clears a completion marker that no named receipt supports', function (): void {
    $seeded = repairSeed(envelopeOverrides: [
        'projection_completed_at' => '2026-08-21T10:30:00+00:00',
    ]);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    // A marker with no receipt behind it retires an envelope whose projection never
    // happened, so it is cleared and the row becomes eligible again.
    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['changed_fields'])->toBe(['projection_completed_at', 'available_at'])
        ->and(repairEnvelope($seeded['event_id'])['projection_completed_at'])->toBeNull();
});

it('is a no-op the second time, touching neither timestamps nor transport backoff', function (): void {
    // REPAIR IS NOT AN IMPLICIT FORCE-REDELIVERY COMMAND. Running it twice must be
    // indistinguishable from running it once.
    $seeded = repairSeed(envelopeOverrides: ['aggregate_version' => 9]);

    repairRun($seeded['event_id']);
    $afterFirst = repairEnvelope($seeded['event_id']);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_UNCHANGED)
        ->and($result['mutation_state'])->toBe(RepairMutationState::NoChanges->value)
        ->and($result['changed_fields'])->toBe([])
        ->and($result['history_reinitialized'])->toBeFalse()
        ->and(repairEnvelope($seeded['event_id']))->toBe($afterFirst);
});

it('treats a re-rendered payload as already correct rather than as drift', function (): void {
    $seeded = repairSeed();

    // The same six keys in another order describe the same payload. Because both
    // columns are jsonb PostgreSQL normalises them anyway; what this pins is that a
    // consistent envelope is a no-op, which is the operator-visible contract.
    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_UNCHANGED)
        ->and($result['changed_fields'])->toBe([]);
});

it('changes nothing outside payment_outbox, and no other envelope', function (): void {
    $merchantId = Merchant::factory()->create()->getKey();
    $target = repairSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: true);
    $bystander = repairSeed(merchantId: $merchantId);

    DB::table('payment_details')->insert([
        'payment_id' => $target['payment_id'],
        'source_event_id' => $target['event_id'],
        'merchant_id' => $merchantId,
        'amount_minor' => 1_250,
        'currency' => 'USD',
        'status' => PaymentProjectionConsumer::INITIATED_STATUS,
        'aggregate_version' => 1,
        'initiated_at' => '2026-08-21T10:11:12.123456+00:00',
    ]);

    $untouchedBefore = repairUntouchedState();
    $bystanderBefore = repairEnvelope($bystander['event_id']);

    [$exitCode] = repairRun($target['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        // Canonical events, projector receipts, read rows and HTTP idempotency
        // receipts are all exactly as they were.
        ->and(repairUntouchedState())->toEqual($untouchedBefore)
        ->and(repairEnvelope($bystander['event_id']))->toBe($bystanderBefore);
});

it('does not repair the read-model discrepancy it can see', function (): void {
    // A RECEIPT WITHOUT ITS READ ROW SURVIVES REPAIR AS THE CORRUPTION IT WAS.
    // Read-model recovery is T11's rebuild; a successful repair is a claim about
    // one envelope, not about the payment's derived state.
    $seeded = repairSeed(withEnvelope: false, withReceipt: true);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_CREATED)
        ->and(DB::connection(IntegrationDatabase::OBSERVER)
            ->table('payment_details')->where('source_event_id', $seeded['event_id'])->exists())
        ->toBeFalse();

    // And the verifier still reports it, which is how an operator learns that the
    // envelope is fixed and the read model is not.
    Artisan::call('payments:projections:verify');

    /** @var array<string, mixed> $verification */
    $verification = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($verification['finding_counts']))
        ->toContain('projector_receipt_without_read_row');
});

/*
|--------------------------------------------------------------------------
| Locking, commit uncertainty, and the audit
|--------------------------------------------------------------------------
*/

/** Rebind repair with a logger that throws on its $failOnCall'th record. */
function repairWithFailingLogger(int $failOnCall): void
{
    app()->bind(OutboxRepair::class, fn ($app): DatabaseOutboxRepair => new DatabaseOutboxRepair(
        $app->make(DatabaseManager::class)->connection(),
        new FailingLogger($failOnCall),
    ));
}

it('contends on canonical identity even when there is no envelope to lock', function (): void {
    // THE SERIALISATION CLAIM. Two repairs of a MISSING envelope have no outbox row
    // to contend on, so without the canonical row lock both would find nothing and
    // both would insert — one dying on the aggregate-identity unique constraint
    // with an error describing a conflict rather than a race. Holding that row from
    // a second connection is what proves the contention point is canonical history.
    $seeded = repairSeed(withEnvelope: false);

    $lockConnection = DB::connection(IntegrationDatabase::LOCK);
    $lockConnection->beginTransaction();
    $lockConnection->select(
        'select id from stored_events where aggregate_uuid = ?::uuid for update',
        [$seeded['payment_id']],
    );

    [$exitCode, $result] = repairRun($seeded['event_id']);

    // EXIT 2, NOT EXIT 1, AND THE DISTINCTION IS THE POINT. Nothing was written
    // either way, but "the command declined" and "the command did not finish" are
    // different answers, and only the second means the operator still does not know
    // whether this envelope needs repairing.
    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($exitCode)->not->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_FAILED)
        ->and($result['reason'])->toBe(RepairReason::StatementTimeout->value)
        // THE MUTATION STATE IS THE REAL TRANSACTION OUTCOME, not a constant: the
        // rollback was confirmed here, which is a different fact from an unknown
        // commit and must not be reported as one.
        ->and($result['mutation_state'])->toBe(RepairMutationState::RolledBack->value)
        ->and(repairEnvelope($seeded['event_id']))->toBeNull();
});

it('sees a competing repair committed result rather than overwriting it', function (): void {
    // READ COMMITTED, NOT REPEATABLE READ. After waiting on the canonical lock the
    // second repair must observe what the first one committed; a snapshot taken
    // before the wait would hide it and the second repair would try to insert a row
    // that already exists.
    $seeded = repairSeed(withEnvelope: false);

    $lockConnection = DB::connection(IntegrationDatabase::LOCK);
    $lockConnection->beginTransaction();
    $lockConnection->select(
        'select id from stored_events where aggregate_uuid = ?::uuid for update',
        [$seeded['payment_id']],
    );
    $lockConnection->table('payment_outbox')->insert([
        'event_id' => $seeded['event_id'],
        'event_type' => PaymentInitiated::ALIAS,
        'schema_version' => 1,
        'aggregate_uuid' => $seeded['payment_id'],
        'aggregate_version' => 1,
        'payload' => json_encode([
            'eventId' => $seeded['event_id'],
            'paymentId' => $seeded['payment_id'],
            'merchantId' => $seeded['merchant_id'],
            'amountMinor' => 1_250,
            'currency' => 'USD',
            'occurredAt' => '2026-08-21T10:11:12.123456+00:00',
        ]),
        'occurred_at' => '2026-08-21T10:11:12.123456+00:00',
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $lockConnection->commit();

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($result['outcome'])->toBe(RepairOutcome::OUTCOME_UNCHANGED)
        ->and($result['mutation_state'])->toBe(RepairMutationState::NoChanges->value);
});

it('reports an unknown commit outcome instead of claiming the work rolled back', function (): void {
    // TransactionCommitting fires inside Connection::commit(), so a listener that
    // throws there is a failure at the exact boundary where the answer is not
    // knowable. Nothing may be retried on the strength of it.
    $seeded = repairSeed(withEnvelope: false);

    Event::listen(TransactionCommitting::class, function (): void {
        throw new RuntimeException('the commit boundary failed');
    });

    [$exitCode, $result] = repairRun($seeded['event_id']);

    Event::forget(TransactionCommitting::class);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RepairReason::CommitOutcomeUnknown->value)
        ->and($result['mutation_state'])->toBe(RepairMutationState::Unknown->value);
});

it('never begins the mutation when operator intent cannot be recorded', function (): void {
    // A change to durable delivery state with no evidence that anyone asked for it
    // is the one thing an audited command must not produce.
    $seeded = repairSeed(withEnvelope: false);

    repairWithFailingLogger(failOnCall: 1);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RepairReason::IntentAuditFailed->value)
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value)
        ->and(repairEnvelope($seeded['event_id']))->toBeNull();
});

it('reports a committed repair whose record failed, without claiming a rollback', function (): void {
    // DATABASE COMMIT AND LOG PERSISTENCE ARE NOT ONE TRANSACTION. The row changed
    // and the operator's record of it did not, which is neither a clean success nor
    // a clean failure and must be reported as exactly that.
    $seeded = repairSeed(withEnvelope: false);

    repairWithFailingLogger(failOnCall: 2);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RepairReason::AuditFailed->value)
        ->and($result['mutation_state'])->toBe(RepairMutationState::Committed->value)
        // The commit really did happen, which is why claiming a rollback would be a lie.
        ->and(repairEnvelope($seeded['event_id']))->not->toBeNull();
});

it('reports a no-op whose record failed as no changes rather than as committed', function (): void {
    $seeded = repairSeed();

    repairWithFailingLogger(failOnCall: 2);

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RepairReason::AuditFailed->value)
        // Nothing durable to report as committed.
        ->and($result['mutation_state'])->toBe(RepairMutationState::NoChanges->value);
});

it('audits intent and outcome under one operation id, with only safe fields', function (): void {
    $seeded = repairSeed(withEnvelope: false, envelopeOverrides: []);

    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = $logged->context;
    });

    [, $result] = repairRun($seeded['event_id']);

    $audited = array_values(array_filter(
        $records->getArrayCopy(),
        static fn (array $context): bool => str_starts_with((string) ($context['category'] ?? ''), 'outbox_repair_'),
    ));

    expect($audited)->toHaveCount(2)
        ->and($audited[0]['category'])->toBe('outbox_repair_intent')
        ->and($audited[1]['category'])->toBe('outbox_repair_outcome')
        // CORRELATED, and carrying the operator's assertion on both records.
        ->and($audited[0]['operation_id'])->toBe($result['operation_id'])
        ->and($audited[1]['operation_id'])->toBe($result['operation_id'])
        ->and($audited[0]['delivery_quiesced'])->toBeTrue()
        // NAMES ONLY: no payload, no previous or replacement value, no key.
        ->and($audited[1]['changed_fields'])->toBe($result['changed_fields'])
        ->and(json_encode($audited))->not->toContain('amountMinor');
});

it('preserves a failed transaction state when the outcome audit also fails', function (bool $commitFailure): void {
    $seeded = repairSeed(withEnvelope: false);
    repairWithFailingLogger(failOnCall: 2);

    if ($commitFailure) {
        Event::listen(TransactionCommitting::class, function (): void {
            throw new RuntimeException('commit boundary unavailable');
        });
    } else {
        $lock = DB::connection(IntegrationDatabase::LOCK);
        $lock->beginTransaction();
        $lock->select('select id from stored_events where aggregate_uuid = ? for update', [$seeded['payment_id']]);
    }

    try {
        [$exitCode, $result] = repairRun($seeded['event_id']);
    } finally {
        Event::forget(TransactionCommitting::class);
    }

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['reason'])->toBe(RepairReason::AuditFailed->value)
        ->and($result['mutation_state'])->toBe($commitFailure ? 'unknown' : 'rolled_back')
        ->and(repairEnvelope($seeded['event_id']))->toBeNull();
})->with(['rollback then audit failure' => false, 'unknown commit then audit failure' => true]);

it('owns read committed isolation even when the session default differs', function (): void {
    $seeded = repairSeed(withEnvelope: false);
    $isolation = null;
    DB::statement('set default_transaction_isolation = \'repeatable read\'');
    Event::listen(TransactionCommitting::class, function () use (&$isolation): void {
        $isolation = DB::selectOne('show transaction_isolation')->transaction_isolation;
    });

    try {
        [$exitCode] = repairRun($seeded['event_id']);
    } finally {
        Event::forget(TransactionCommitting::class);
        DB::statement('set default_transaction_isolation = default');
    }

    expect($exitCode)->toBe(RepairOutcome::EXIT_DONE)
        ->and($isolation)->toBe('read committed');
});

it('does not borrow an outer transaction or claim its savepoint as a durable repair', function (): void {
    $seeded = repairSeed(withEnvelope: false);
    DB::beginTransaction();

    [$exitCode, $result] = repairRun($seeded['event_id']);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value)
        ->and(DB::connection()->transactionLevel())->toBe(1)
        ->and(DB::table('payment_outbox')->where('event_id', $seeded['event_id'])->exists())->toBeFalse();
});

it('preserves the missing acknowledgement when its refusal audit fails', function (): void {
    repairWithFailingLogger(failOnCall: 1);

    [$exitCode, $result] = repairRun((string) Str::uuid7(), quiesced: false);

    expect($exitCode)->toBe(RepairOutcome::EXIT_FAILED)
        ->and($result['delivery_quiesced'])->toBeFalse()
        ->and($result['mutation_state'])->toBe(RepairMutationState::None->value);
});

it('audits a refusal with a safe reason and no mutation state', function (): void {
    $records = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $logged) use ($records): void {
        $records[] = $logged->context;
    });

    repairRun('not-a-uuid');

    $audited = array_values(array_filter(
        $records->getArrayCopy(),
        static fn (array $context): bool => ($context['category'] ?? '') === 'outbox_repair_outcome',
    ));

    // Refused before any database work, so there is no intent record and nothing to
    // roll back — and the identifier is still not echoed.
    expect($audited)->toHaveCount(1)
        ->and($audited[0]['reason'])->toBe(RepairReason::MalformedEventId->value)
        ->and($audited[0]['mutation_state'])->toBe(RepairMutationState::None->value)
        ->and($audited[0]['event_id'])->toBeNull()
        ->and(json_encode($audited))->not->toContain('not-a-uuid');
});
