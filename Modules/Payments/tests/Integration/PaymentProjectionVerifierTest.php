<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Application\Verification\IncompleteVerificationScope;
use Modules\Payments\Application\Verification\ProjectionVerifier;
use Modules\Payments\Application\Verification\VerificationClassification;
use Modules\Payments\Application\Verification\VerificationFinding;
use Modules\Payments\Application\Verification\VerificationReport;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Verification\DatabaseProjectionVerifier;
use Modules\Payments\Infrastructure\Verification\VerificationConfiguration;
use Modules\Payments\Tests\Integration\IntegrationDatabase;
use Modules\Payments\Tests\Integration\ProjectionGateLocks;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Canonical and reverse verification - `payments:projections:verify`
|--------------------------------------------------------------------------
|
| IN THE INTEGRATION SUITE BECAUSE THE SUBJECT IS A REAL SNAPSHOT. The audit opens
| its own REPEATABLE READ, READ ONLY transaction and commits it. Under
| RefreshDatabase that becomes a savepoint inside an outer transaction, which
| cannot take an isolation level at all — `SET TRANSACTION` only applies before
| the first query of a real transaction — so every claim here would be vacuous.
|
| THE COMMAND IS INVOKED THROUGH Artisan, not by calling the class, so the
| signature, the container bindings and the registration are all exercised. A test
| that new-ed it would pass with the command unregistered, which is exactly the
| failure an operator would meet.
|
| The independent reverse passes cover records the canonical walk cannot reach.
| The `scanned` assertions pin their scope rather than leaving it implied.
|
*/

/**
 * One canonical event plus whichever derived rows the scenario wants.
 *
 * THE CANONICAL ROW IS THE SOURCE and every default below is derived from it, so
 * a test that changes nothing produces a genuinely consistent payment rather than
 * one that happens to agree.
 *
 * @return array{event_id: string, payment_id: string, merchant_id: int, stored_event_id: int}
 */
function verifierSeed(
    ?int $merchantId = null,
    ?string $occurredAt = null,
    bool $withEnvelope = true,
    bool $withReceipt = true,
    bool $withReadRow = true,
    bool $withMarker = true,
    ?string $exhaustedAt = null,
    array $canonicalOverrides = [],
    array $payloadOverrides = [],
    array $envelopeOverrides = [],
    array $readRowOverrides = [],
): array {
    $merchantId ??= Merchant::factory()->create()->getKey();
    $eventId = (string) Str::uuid7();
    $paymentId = (string) Str::uuid7();
    $occurredAt ??= CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT);

    $payload = array_merge([
        'eventId' => $eventId,
        'paymentId' => $paymentId,
        'merchantId' => $merchantId,
        'amountMinor' => 1_250,
        'currency' => 'USD',
        'occurredAt' => $occurredAt,
    ], $payloadOverrides);

    $storedEventId = DB::table('stored_events')->insertGetId(array_merge([
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
            'available_at' => now(),
            'projection_completed_at' => $withMarker ? now() : null,
            'exhausted_at' => $exhaustedAt,
            'publish_count' => 1,
            'consecutive_transport_failures' => 0,
            'projection_failure_count' => 0,
            'projection_failure_generation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $envelopeOverrides));
    }

    if ($withReceipt) {
        DB::table('payment_projector_receipts')->insert([
            'projector_name' => PaymentProjectionConsumer::PROJECTOR_NAME,
            'event_id' => $eventId,
            'created_at' => now(),
        ]);
    }

    if ($withReadRow) {
        DB::table('payment_details')->insert(array_merge([
            'payment_id' => $paymentId,
            'source_event_id' => $eventId,
            'merchant_id' => $merchantId,
            'amount_minor' => 1_250,
            'currency' => 'USD',
            'status' => PaymentProjectionConsumer::INITIATED_STATUS,
            'aggregate_version' => 1,
            'initiated_at' => $occurredAt,
        ], $readRowOverrides));
    }

    return [
        'event_id' => $eventId,
        'payment_id' => $paymentId,
        'merchant_id' => $merchantId,
        'stored_event_id' => (int) $storedEventId,
    ];
}

/** The audit as an operator runs it: exit code plus the one JSON object it printed. */
function verifierRun(): array
{
    $exitCode = Artisan::call('payments:projections:verify');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $decoded];
}

/**
 * The four-pass `scanned` map, built in report order.
 *
 * SPELLED OUT RATHER THAN PARTIALLY ASSERTED. A complete audit must carry all
 * four keys, so a test that checked only the pass it cared about would stop
 * noticing if another pass silently stopped running.
 *
 * @return array<string, int>
 */
function verifierScanned(int $canonical, int $receipts, int $readRows, int $envelopes): array
{
    return [
        VerificationReport::PASS_CANONICAL_EVENTS => $canonical,
        VerificationReport::PASS_PROJECTOR_RECEIPTS => $receipts,
        VerificationReport::PASS_READ_ROWS => $readRows,
        VerificationReport::PASS_OUTBOX_ENVELOPES => $envelopes,
    ];
}

function verifierFindings(array $report): array
{
    /** @var array<string, int> $counts */
    $counts = $report['finding_counts'];

    return array_keys($counts);
}

/** Committed row counts, read from the observer so only durable state is counted. */
function verifierCounts(): array
{
    $counts = [];

    foreach (['stored_events', 'payment_outbox', 'payment_projector_receipts', 'payment_details'] as $table) {
        $counts[$table] = IntegrationDatabase::observedCount($table);
    }

    return $counts;
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

it('reports a closed maintenance gate as incomplete before scanning and preserves the observed control state', function (string $closure): void {
    // This contradiction must become visible only after the gate reopens.
    verifierSeed(readRowOverrides: ['amount_minor' => 9_999]);
    $counts = verifierCounts();

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
    [$exitCode, $report] = verifierRun();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['reason'])->toBe('rebuild_in_progress')
        ->and($report['complete'])->toBeFalse()
        ->and($report['projection_control_state'])->toBe($control?->state)
        ->and($report['high_watermark'])->toBeNull()
        ->and($report['audit_at'])->toBeNull()
        ->and($report['scanned'])->toBe([])
        ->and($report['finding_counts'])->toBe([])
        ->and($report['examples'])->toBe([])
        ->and(verifierCounts())->toBe($counts)
        ->and(DB::table('payment_projection_controls')->first())->toEqual($control)
        ->and(DB::transactionLevel())->toBe(0);

    foreach ($queries as $query) {
        expect($query)->not->toMatch('/\b(stored_events|payment_outbox|payment_details|payment_projector_receipts)\b/i');
    }

    ProjectionGateLocks::releaseAll(IntegrationDatabase::LOCK);
    DB::table('payment_projection_controls')->updateOrInsert(
        ['projection' => ProjectionGate::PAYMENT_DETAILS], $this->controlBefore,
    );
    [$reopened, $report] = verifierRun();
    expect($reopened)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['complete'])->toBeTrue()
        ->and($report['projection_control_state'])->toBe('ready');
})->with(['exclusive lock before claim' => 'lock', 'abandoned rebuilding row' => 'row', 'missing control row' => 'missing']);

it('holds the shared gate on the audit snapshot backend until verification finishes', function (): void {
    verifierSeed();
    $pid = ProjectionGateLocks::pid(DB::getDefaultConnection());
    expect($pid)->not->toBe(ProjectionGateLocks::pid(IntegrationDatabase::LOCK));
    $observed = [];

    DB::listen(function (QueryExecuted $query) use (&$observed, $pid): void {
        if ($query->connection !== DB::connection() || ! str_contains($query->sql, 'as high_watermark')) {
            return;
        }

        $observed['locks'] = array_values(array_filter(
            ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID),
            static fn (array $lock): bool => $lock['pid'] === $pid && $lock['granted']
                && $lock['mode'] === 'ShareLock' && $lock['objsubid'] === 2,
        ));
        $observed['isolation'] = DB::selectOne("select current_setting('transaction_isolation') as value")->value;
        $observed['read_only'] = DB::selectOne("select current_setting('transaction_read_only') as value")->value;
        $observed['exclusive'] = (int) DB::connection(IntegrationDatabase::LOCK)->selectOne(
            'select pg_try_advisory_lock(?, ?)::int as acquired',
            [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
        )->acquired;
    });

    [$exitCode, $report] = verifierRun();
    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['projection_control_state'])->toBe('ready')
        ->and($observed['locks'])->toHaveCount(1)
        ->and($observed['isolation'])->toBe('repeatable read')
        ->and($observed['read_only'])->toBe('on')
        ->and($observed['exclusive'])->toBe(0)
        ->and(ProjectionGateLocks::onKey(ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID))->toBe([]);

    expect((int) DB::connection(IntegrationDatabase::LOCK)->selectOne(
        'select pg_try_advisory_lock(?, ?)::int as acquired',
        [ProjectionGate::CLASS_ID, ProjectionGate::PAYMENT_DETAILS_OBJECT_ID],
    )->acquired)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The empty and the consistent case
|--------------------------------------------------------------------------
*/

it('reports an empty history as consistent, with a zero watermark', function (): void {
    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['complete'])->toBeTrue()
        ->and($report['outcome'])->toBe(VerificationReport::OUTCOME_CONSISTENT)
        ->and($report['reason'])->toBe(VerificationReport::REASON_COMPLETE)
        ->and($report['high_watermark'])->toBe(0)
        ->and($report['audit_at'])->toBeString()
        ->and($report['scanned'])->toBe(verifierScanned(0, 0, 0, 0))
        ->and($report['finding_counts'])->toBe([])
        ->and($report['examples'])->toBe([]);
});

it('reports fully projected state as consistent and names the watermark it reached', function (): void {
    $seeded = verifierSeed();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['high_watermark'])->toBe($seeded['stored_event_id'])
        ->and($report['scanned'])->toBe(verifierScanned(1, 1, 1, 1))
        ->and($report['classification_counts'][VerificationClassification::Consistent->value])->toBe(1)
        ->and($report['finding_counts'])->toBe([]);
});

it('reports every classification at zero rather than omitting the ones that did not occur', function (): void {
    verifierSeed();

    [, $report] = verifierRun();

    // DETERMINISTIC SHAPE. A consumer that read classification_counts.inconsistent
    // would otherwise have to distinguish "absent" from "zero" on every run.
    expect(array_keys($report['classification_counts']))->toBe([
        'consistent', 'in_flight', 'delivery_overdue', 'exhausted', 'inconsistent',
    ]);
});

/*
|--------------------------------------------------------------------------
| Delivery state: in flight, overdue, exhausted
|--------------------------------------------------------------------------
*/

it('classifies a fresh incomplete delivery as in flight and still exits successfully', function (): void {
    verifierSeed(withReceipt: false, withReadRow: false, withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['classification_counts'][VerificationClassification::InFlight->value])->toBe(1)
        ->and($report['finding_counts'])->toBe([]);
});

it('classifies the same delivery as overdue once it is past grace, and asks for attention', function (): void {
    verifierSeed(
        occurredAt: CarbonImmutable::now()->utc()->subMinutes(10)->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
    );

    [$exitCode, $report] = verifierRun();

    // EXIT 3, NOT 1. Age is not corruption evidence, and an operator must still
    // be told that delivery has stopped moving.
    expect($exitCode)->toBe(VerificationReport::EXIT_ATTENTION)
        ->and($report['outcome'])->toBe(VerificationReport::OUTCOME_ATTENTION)
        ->and($report['classification_counts'][VerificationClassification::DeliveryOverdue->value])->toBe(1)
        ->and($report['classification_counts'][VerificationClassification::Inconsistent->value])->toBe(0);
});

it('classifies an exhausted envelope separately from ordinary lag', function (): void {
    verifierSeed(
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        exhaustedAt: (string) now(),
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_ATTENTION)
        ->and($report['classification_counts'][VerificationClassification::Exhausted->value])->toBe(1)
        ->and($report['classification_counts'][VerificationClassification::DeliveryOverdue->value])->toBe(0);
});

it('reports a future event time as an operational warning rather than as corruption', function (): void {
    verifierSeed(
        occurredAt: CarbonImmutable::now()->utc()->addHour()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_ATTENTION)
        ->and(verifierFindings($report))->toBe([VerificationFinding::EventTimeInFuture->value])
        ->and($report['classification_counts'][VerificationClassification::Inconsistent->value])->toBe(0)
        ->and($report['classification_counts'][VerificationClassification::InFlight->value])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Contradictions
|--------------------------------------------------------------------------
*/

it('fails a canonical event whose outbox envelope does not exist', function (): void {
    verifierSeed(withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([VerificationFinding::OutboxEnvelopeMissing->value])
        ->and($report['classification_counts'][VerificationClassification::Inconsistent->value])->toBe(1);
});

it('never lets recent age hide a missing envelope', function (): void {
    // The event was written a moment ago, so grace would cover an ordinary
    // incomplete delivery. It must not cover this one: T6 commits the event and
    // its envelope together, so there is no lag that explains the absence.
    verifierSeed(
        occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['classification_counts'][VerificationClassification::InFlight->value])->toBe(0);
});

it('names each envelope column that disagrees with canonical history', function (): void {
    verifierSeed(envelopeOverrides: [
        'aggregate_version' => 7,
        'occurred_at' => '2020-01-01T00:00:00.000000+00:00',
    ]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        // TWO FACTS ABOUT ONE ROW, not one counted twice: the envelope for this
        // canonical event has wrong columns, AND the envelope as it now stands
        // matches no canonical event, because payment_outbox's identity is the
        // (aggregate_uuid, aggregate_version) pair that its corrupted version broke.
        // The receipt is NOT accused - it resolves on the aggregate uuid alone.
        ->and(verifierFindings($report))->toBe([
            VerificationFinding::OutboxFieldMismatch->value,
            VerificationFinding::OutboxEnvelopeOrphaned->value,
        ])
        ->and($report['examples'][0]['fields'])->toBe(['aggregate_version', 'occurred_at']);
});

it('reports a payload whose value actually changed', function (): void {
    $seeded = verifierSeed();

    DB::table('payment_outbox')->where('event_id', $seeded['event_id'])->update([
        'payload' => json_encode([
            'eventId' => $seeded['event_id'],
            'paymentId' => $seeded['payment_id'],
            'merchantId' => $seeded['merchant_id'],
            'amountMinor' => 9_999,
            'currency' => 'USD',
            'occurredAt' => (string) DB::table('payment_outbox')
                ->where('event_id', $seeded['event_id'])->value('occurred_at'),
        ]),
    ]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['examples'][0]['fields'])->toContain('payload');
});

it('fails a projector receipt whose read row does not exist', function (): void {
    verifierSeed(withReadRow: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::ProjectorReceiptWithoutReadRow->value]);
});

it('fails a read row whose projector receipt does not exist', function (): void {
    verifierSeed(withReceipt: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([
            VerificationFinding::ReadRowWithoutProjectorReceipt->value,
            VerificationFinding::CompletionMarkerWithoutReceipt->value,
        ]);
});

it('fails a completion marker that no named receipt supports', function (): void {
    verifierSeed(withReceipt: false, withReadRow: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::CompletionMarkerWithoutReceipt->value]);
});

it('fails a receipt whose completion marker was never written', function (): void {
    verifierSeed(withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::CompletionMarkerMissing->value]);
});

it('does not accept another projector receipt as completion evidence', function (): void {
    $seeded = verifierSeed(withReceipt: false, withMarker: false);

    DB::table('payment_projector_receipts')->insert([
        'projector_name' => 'some_other_projection',
        'event_id' => $seeded['event_id'],
        'created_at' => now(),
    ]);

    [$exitCode, $report] = verifierRun();

    // The read row exists and this projection's receipt does not, which is what
    // the foreign receipt must not be allowed to paper over.
    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::ReadRowWithoutProjectorReceipt->value]);
});

it('names each read-model column that disagrees with canonical history', function (): void {
    verifierSeed(readRowOverrides: [
        'amount_minor' => 9_999,
        'currency' => 'EUR',
        'status' => 'settled',
        'aggregate_version' => 4,
        'initiated_at' => '2020-01-01T00:00:00.000000+00:00',
    ]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['examples'][0]['fields'])
        ->toBe(['amount_minor', 'currency', 'status', 'aggregate_version', 'initiated_at']);
});

it('detects a merchant that disagrees with canonical history', function (): void {
    $other = Merchant::factory()->create()->getKey();

    verifierSeed(readRowOverrides: ['merchant_id' => $other]);

    [, $report] = verifierRun();

    expect($report['examples'][0]['fields'])->toBe(['merchant_id']);
});

it('detects a read row projected from a different source event', function (): void {
    $seeded = verifierSeed(withReceipt: false, withMarker: false);

    DB::table('payment_details')->where('payment_id', $seeded['payment_id'])
        ->update(['source_event_id' => (string) Str::uuid7()]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toContain(VerificationFinding::ReadRowWrongSourceEvent->value);
});

it('detects microsecond drift in event time rather than rounding it away', function (): void {
    $occurredAt = '2026-08-21T10:11:12.123456+00:00';

    verifierSeed(occurredAt: $occurredAt, readRowOverrides: [
        'initiated_at' => '2026-08-21T10:11:12.123457+00:00',
    ]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['examples'][0]['fields'])->toBe(['initiated_at']);
});

/*
|--------------------------------------------------------------------------
| Canonical history the audit cannot decode
|--------------------------------------------------------------------------
*/

it('reports a Payments alias it cannot decode instead of skipping it', function (): void {
    // SCOPED BY PREFIX, NOT BY THE ONE DECODABLE ALIAS. A future Payments event
    // that this audit does not understand has to be visible, or "no findings"
    // would mean "nothing I looked for".
    verifierSeed(
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        canonicalOverrides: ['event_class' => 'payments.payment_settled'],
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([VerificationFinding::CanonicalAliasUnsupported->value])
        ->and($report['examples'][0]['event_id'])->toBeNull()
        ->and($report['examples'][0]['stored_event_id'])->toBeInt();
});

it('leaves another bounded context outside the audit entirely', function (): void {
    DB::table('stored_events')->insert([
        'aggregate_uuid' => (string) Str::uuid7(),
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => 'merchant.something_happened',
        'event_properties' => json_encode(['whatever' => true]),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ]);

    [$exitCode, $report] = verifierRun();

    // The watermark still moves — it is the global maximum — but nothing was
    // scanned and nothing was found.
    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['high_watermark'])->toBeGreaterThan(0)
        ->and($report['scanned'])->toBe(verifierScanned(0, 0, 0, 0));
});

it('reports a malformed canonical payload rather than deserialising it', function (): void {
    verifierSeed(
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        payloadOverrides: ['amountMinor' => 'twelve-fifty'],
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([VerificationFinding::CanonicalPayloadMalformed->value]);
});

it('reports a canonical payload that disagrees with its own aggregate identity', function (): void {
    verifierSeed(
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        payloadOverrides: ['paymentId' => (string) Str::uuid7()],
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::CanonicalIdentityContradictory->value]);
});

it('reports an unusable canonical event id rather than querying uuid columns with it', function (): void {
    // WITHOUT THIS CHECK the id below reaches three uuid columns as a parameter,
    // PostgreSQL raises 22P02, and the whole audit aborts as an incomplete
    // database error — hiding every other finding and never reporting this one.
    verifierSeed(
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        payloadOverrides: ['eventId' => 'not-a-uuid'],
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['complete'])->toBeTrue()
        ->and(verifierFindings($report))->toBe([VerificationFinding::CanonicalPayloadMalformed->value]);
});

it('keeps auditing the rest of the batch after an unusable event id', function (): void {
    // The corrupt row must not take its neighbours down with it: the batch's other
    // events are still compared, and their derived state still classified.
    $merchantId = Merchant::factory()->create()->getKey();

    verifierSeed(
        merchantId: $merchantId,
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        payloadOverrides: ['eventId' => 'not-a-uuid'],
    );
    verifierSeed(merchantId: $merchantId);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['scanned'])->toBe(verifierScanned(2, 1, 1, 1))
        ->and($report['classification_counts'][VerificationClassification::Consistent->value])->toBe(1)
        ->and($report['classification_counts'][VerificationClassification::Inconsistent->value])->toBe(1);
});

it('reports an unsupported canonical event version', function (): void {
    verifierSeed(
        withEnvelope: false,
        withReceipt: false,
        withReadRow: false,
        withMarker: false,
        canonicalOverrides: ['event_version' => 2],
    );

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))
        ->toBe([VerificationFinding::CanonicalSchemaVersionUnsupported->value]);
});

/*
|--------------------------------------------------------------------------
| Boundedness, precedence and non-mutation
|--------------------------------------------------------------------------
*/

it('walks more events than one batch holds without repeating or missing any', function (): void {
    config(['payments.verification.batch_size' => 2]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 5; $i++) {
        verifierSeed(merchantId: $merchantId);
    }

    // A GAP IN THE ID SEQUENCE, so a cursor that assumed contiguity would stall or
    // skip. Deleting the middle canonical row also leaves its derived rows behind,
    // which the reverse passes now report.
    $ids = DB::table('stored_events')->orderBy('id')->pluck('id');
    DB::table('stored_events')->where('id', $ids[2])->delete();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        // The deleted canonical row leaves its derived rows behind, and the reverse
        // passes are what now see them: five receipts, read rows and envelopes for
        // four surviving events.
        ->and($report['scanned'])->toBe(verifierScanned(4, 5, 5, 5))
        ->and($report['classification_counts'][VerificationClassification::Consistent->value])->toBe(4)
        ->and($report['finding_counts'])->toBe([
            VerificationFinding::ProjectorReceiptOrphaned->value => 1,
            VerificationFinding::ReadRowOrphaned->value => 1,
            VerificationFinding::OutboxEnvelopeOrphaned->value => 1,
        ]);
});

it('caps retained examples while still counting every finding', function (): void {
    config(['payments.verification.batch_size' => 2, 'payments.verification.example_limit' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 3; $i++) {
        verifierSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);
    }

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['finding_counts'][VerificationFinding::OutboxEnvelopeMissing->value])->toBe(3)
        ->and($report['examples'])->toHaveCount(1)
        ->and($report['examples_truncated'])->toBeTrue();
});

it('stops incomplete rather than declaring an uninspectable payload consistent', function (): void {
    // The bytes were never transferred, so they can be called neither consistent
    // nor corrupt. Exit 2 outranks everything, including the inconsistency the
    // same run had already found.
    config(['payments.verification.max_payload_bytes' => 32]);

    verifierSeed(withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['complete'])->toBeFalse()
        ->and($report['outcome'])->toBe(VerificationReport::OUTCOME_INCOMPLETE)
        ->and($report['reason'])->toBe('payload_exceeds_inspection_limit');
});

it('validates the shipped configuration, so the defaults are the ones under test', function (): void {
    // The Unit suite drives VerificationConfiguration with a fixture array and has
    // no application to read config() from. This is what stops that fixture from
    // drifting away from config/payments.php and proving something about nothing.
    $config = VerificationConfiguration::fromArray((array) config('payments.verification'));

    expect($config->batchSize)->toBe(100)
        ->and($config->exampleLimit)->toBe(50)
        ->and($config->graceSeconds)->toBe(30)
        ->and($config->statementTimeoutMs)->toBe(2_000)
        ->and($config->transactionTimeoutMs)->toBe(120_000)
        ->and($config->maxPayloadBytes)->toBe(65_536);
});

it('stops on its work budget and keeps the findings it had already made', function (): void {
    // A FAKE MONOTONIC CLOCK, NOT A SLEEP. The budget reads exactly one thing, so
    // controlling that one thing proves the abort deterministically - and proves
    // the harder half too: a run that stopped keeps its findings and is still
    // reported incomplete rather than as a clean pass over history it never read.
    config(['payments.verification.batch_size' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 2; $i++) {
        verifierSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);
    }

    // Start, one loop check that passes, then a reading past the deadline.
    $readings = [0, 0, PHP_INT_MAX];

    app()->bind(ProjectionVerifier::class, fn ($app): DatabaseProjectionVerifier => new DatabaseProjectionVerifier(
        $app->make(DatabaseManager::class)->connection(),
        $app->make(VerificationConfiguration::class),
        static function () use (&$readings): int {
            return array_shift($readings) ?? PHP_INT_MAX;
        },
    ));

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['complete'])->toBeFalse()
        ->and($report['reason'])->toBe(DatabaseProjectionVerifier::REASON_WORK_BUDGET)
        ->and($report['scanned'])->toBe([VerificationReport::PASS_CANONICAL_EVENTS => 1])
        ->and($report['finding_counts'][VerificationFinding::OutboxEnvelopeMissing->value])->toBe(1);
});

it('refuses an invalid bound before it opens a snapshot', function (): void {
    config(['payments.verification.batch_size' => 0]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['reason'])->toBe('invalid_configuration')
        ->and($report['complete'])->toBeFalse()
        ->and($report['high_watermark'])->toBeNull()
        ->and($report['scanned'])->toBe([])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('includes a nil UUID in every reverse pass instead of reporting empty clean state', function (string $table, string $key, string $pass): void {
    verifierSeed();
    DB::table('stored_events')->delete();
    foreach (['payment_projector_receipts', 'payment_details', 'payment_outbox'] as $other) {
        if ($other !== $table) {
            DB::table($other)->delete();
        }
    }
    DB::table($table)->update([$key => '00000000-0000-0000-0000-000000000000']);
    config(['payments.verification.batch_size' => 1]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['complete'])->toBeTrue()
        ->and($report['scanned'][$pass])->toBe(1);
})->with([
    'receipt' => ['payment_projector_receipts', 'event_id', 'projector_receipts'],
    'read row' => ['payment_details', 'payment_id', 'payment_details'],
    'envelope' => ['payment_outbox', 'event_id', 'payment_outbox'],
]);

it('changes no committed row and leaves each tuple writing transaction intact', function (): void {
    verifierSeed();

    $before = verifierCounts();
    $xminBefore = [
        'payment_outbox' => IntegrationDatabase::observedXmin('payment_outbox'),
        'payment_details' => IntegrationDatabase::observedXmin('payment_details'),
    ];

    verifierRun();

    // xmin IS PostgreSQL'S OWN STATEMENT about which transaction wrote each tuple.
    // Equal counts alone would survive an update; an unchanged xmin would not.
    expect(verifierCounts())->toBe($before)
        ->and(IntegrationDatabase::observedXmin('payment_outbox'))->toBe($xminBefore['payment_outbox'])
        ->and(IntegrationDatabase::observedXmin('payment_details'))->toBe($xminBefore['payment_details']);
});

it('returns safe JSON and exit 2 for an invalid verifier option', function (): void {
    $exitCode = Artisan::call('payments:projections:verify', ['--not-an-option' => true]);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(2)
        ->and($report['reason'])->toBe('invalid_invocation')
        ->and($report['complete'])->toBeFalse()
        ->and($report['scanned'])->toBe([])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('does not borrow an outer transaction or audit inside its savepoint', function (): void {
    verifierSeed();
    $before = verifierCounts();

    DB::beginTransaction();

    // THE EVENT IS THE NON-VACUOUS PART, and the reported fields are not. An
    // unguarded run reaches the same reported values: PostgreSQL refuses
    // `SET TRANSACTION ISOLATION LEVEL` in a subtransaction, its 25001 is not on
    // the mapped list and becomes `database_error`, rollBack() restores the
    // caller's level to 1, and no pass starts, so `scanned` stays empty. Every
    // assertion below except this counter would therefore pass over a savepoint
    // the audit should never have opened. MEASURED: with the guard disabled this
    // test fails here, and not on any assertion after it.
    $began = 0;
    Event::listen(TransactionBeginning::class, function () use (&$began): void {
        $began++;
    });

    try {
        [$exitCode, $report] = verifierRun();
    } finally {
        Event::forget(TransactionBeginning::class);
    }

    expect($began)->toBe(0, 'the audit opened a transaction inside the caller\'s')
        ->and($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['reason'])->toBe(DatabaseProjectionVerifier::REASON_DATABASE_ERROR)
        ->and($report['complete'])->toBeFalse()
        ->and($report['high_watermark'])->toBeNull()
        ->and($report['audit_at'])->toBeNull()
        ->and($report['scanned'])->toBe([])
        ->and(DB::connection()->transactionLevel())->toBe(1)
        ->and(verifierCounts())->toBe($before);
});

it('runs its scan inside a read-only transaction that PostgreSQL itself enforces', function (): void {
    // NOT A MOCKED NO-WRITE EXPECTATION. The audit's non-mutation rests on a real
    // server-side declaration, so this proves that declaration refuses a write on
    // this PostgreSQL — and the query-log assertion below proves the audit issues
    // exactly it.
    $seeded = verifierSeed();

    DB::beginTransaction();

    try {
        DB::statement('set transaction isolation level repeatable read, read only');

        $failure = null;

        try {
            DB::table('payment_outbox')->where('event_id', $seeded['event_id'])
                ->update(['publish_count' => 99]);
        } catch (QueryException $caught) {
            $failure = $caught;
        }

        expect($failure)->not->toBeNull('a write inside the audit transaction was not refused')
            ->and(RetryingAtomicTransaction::sqlStateOf($failure))->toBe('25006');
    } finally {
        DB::rollBack();
    }
});

it('issues the isolation and read-only declaration and no write statement', function (): void {
    verifierSeed();

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    verifierRun();

    $statements = array_map(
        static fn (array $entry): string => strtolower(trim((string) $entry['query'])),
        DB::connection()->getQueryLog(),
    );

    DB::connection()->disableQueryLog();

    expect($statements)->toContain('set transaction isolation level repeatable read, read only');

    foreach ($statements as $statement) {
        expect($statement)->not->toStartWith('insert')
            ->and($statement)->not->toStartWith('update')
            ->and($statement)->not->toStartWith('delete')
            ->and($statement)->not->toStartWith('truncate');
    }
});

/*
|--------------------------------------------------------------------------
| Reverse coverage — what a forward walk structurally cannot reach
|--------------------------------------------------------------------------
*/

it('finds a projector receipt no canonical event accounts for', function (): void {
    // The whole point of a reverse pass: nothing in stored_events points at this
    // receipt, so the forward walk can never arrive at it.
    $seeded = verifierSeed();

    DB::table('stored_events')->where('aggregate_uuid', $seeded['payment_id'])->delete();
    DB::table('payment_details')->where('payment_id', $seeded['payment_id'])->delete();
    DB::table('payment_outbox')->where('event_id', $seeded['event_id'])
        ->update(['aggregate_uuid' => (string) Str::uuid7()]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['scanned'])->toBe(verifierScanned(0, 1, 0, 1))
        ->and(verifierFindings($report))->toBe([
            VerificationFinding::ProjectorReceiptOrphaned->value,
            VerificationFinding::OutboxEnvelopeOrphaned->value,
        ]);
});

it('distinguishes a receipt with no envelope at all from an orphaned one', function (): void {
    // A receipt is written only under the envelope's row lock, so the two cannot
    // come apart through an interrupted delivery. With no envelope there is also
    // no identity left to ask canonical history about, which is why this is its
    // own answer rather than an orphan claim.
    $seeded = verifierSeed();

    DB::table('payment_outbox')->where('event_id', $seeded['event_id'])->delete();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([
            VerificationFinding::OutboxEnvelopeMissing->value,
            VerificationFinding::ProjectorReceiptWithoutEnvelope->value,
        ]);
});

it('finds a read row no canonical event accounts for', function (): void {
    $seeded = verifierSeed(withReceipt: false, withEnvelope: false, withMarker: false);

    DB::table('stored_events')->where('aggregate_uuid', $seeded['payment_id'])->delete();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['scanned'])->toBe(verifierScanned(0, 0, 1, 0))
        ->and(verifierFindings($report))->toBe([VerificationFinding::ReadRowOrphaned->value])
        ->and($report['examples'][0]['payment_id'])->toBe($seeded['payment_id'])
        ->and($report['examples'][0]['stored_event_id'])->toBeNull();
});

it('finds an outbox envelope no canonical event accounts for', function (): void {
    $seeded = verifierSeed(withReceipt: false, withReadRow: false, withMarker: false);

    DB::table('stored_events')->where('aggregate_uuid', $seeded['payment_id'])->delete();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['scanned'])->toBe(verifierScanned(0, 0, 0, 1))
        ->and(verifierFindings($report))->toBe([VerificationFinding::OutboxEnvelopeOrphaned->value])
        ->and($report['examples'][0]['event_id'])->toBe($seeded['event_id'])
        ->and($report['examples'][0]['payment_id'])->toBe($seeded['payment_id']);
});

it('does not accuse a receipt when only the envelope version is corrupt', function (): void {
    // THE MISLABEL THIS SPLIT EXISTS TO PREVENT. The receipt is intact and its
    // payment is in canonical history; only the envelope's identity pair is
    // broken. A receipt pass that resolved on the version pair would send an
    // operator hunting for a deleted event that never existed.
    verifierSeed(envelopeOverrides: ['aggregate_version' => 7]);

    [, $report] = verifierRun();

    expect(verifierFindings($report))->not->toContain(VerificationFinding::ProjectorReceiptOrphaned->value);
});

it('reports duplicate canonical event ids that no single batch could see together', function (): void {
    // ACROSS BATCHES, WITH A BATCH SIZE OF ONE, so the two rows are never in PHP
    // at the same time. Detecting this by comparing within a batch would find
    // nothing; retaining every id seen would be the unbounded memory the audit
    // forbids.
    config(['payments.verification.batch_size' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();
    $sharedEventId = (string) Str::uuid7();

    verifierSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: false, withReadRow: false,
        withMarker: false, payloadOverrides: ['eventId' => $sharedEventId]);
    verifierSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: false, withReadRow: false,
        withMarker: false, payloadOverrides: ['eventId' => $sharedEventId]);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['finding_counts'][VerificationFinding::CanonicalDuplicateEventId->value])->toBe(1)
        ->and($report['examples'])->toContain([
            'finding' => VerificationFinding::CanonicalDuplicateEventId->value,
            'stored_event_id' => null,
            'event_id' => $sharedEventId,
            'payment_id' => null,
            'fields' => [],
        ]);
});

it('gives each reverse pass its own cursor across several batches', function (): void {
    // Each pass keysets its own primary key, so none of them can inherit another's
    // position or stall on a gap. Every record is examined exactly once.
    config(['payments.verification.batch_size' => 2]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 5; $i++) {
        verifierSeed(merchantId: $merchantId);
    }

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['scanned'])->toBe(verifierScanned(5, 5, 5, 5));
});

it('keeps diagnostics capped when every reverse record fails', function (): void {
    config(['payments.verification.batch_size' => 2, 'payments.verification.example_limit' => 2]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 4; $i++) {
        $seeded = verifierSeed(merchantId: $merchantId);
        DB::table('stored_events')->where('aggregate_uuid', $seeded['payment_id'])->delete();
    }

    [$exitCode, $report] = verifierRun();

    // Twelve findings — four receipts, four read rows, four envelopes — counted in
    // full while only two examples are retained.
    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($report['finding_counts'])->toBe([
            VerificationFinding::ProjectorReceiptOrphaned->value => 4,
            VerificationFinding::ReadRowOrphaned->value => 4,
            VerificationFinding::OutboxEnvelopeOrphaned->value => 4,
        ])
        ->and($report['examples'])->toHaveCount(2)
        ->and($report['examples_truncated'])->toBeTrue();
});

it('refuses to call a run complete when a pass never started', function (): void {
    // The guard is structural rather than a convention: a future pass lost to an
    // early return has to fail loudly instead of shrinking the scope of a report
    // that still says `complete: true`.
    expect(fn (): VerificationReport => VerificationReport::completed(
        highWatermark: 1,
        auditAt: '2026-09-02T00:00:00.000000+00:00',
        scanned: [VerificationReport::PASS_CANONICAL_EVENTS => 1],
        classificationCounts: VerificationClassification::zeroed(),
        findingCounts: [],
        examples: [],
        examplesTruncated: false,
    ))->toThrow(IncompleteVerificationScope::class, 'projector_receipts');
});

/*
|--------------------------------------------------------------------------
| The snapshot, and the bounds that end it
|--------------------------------------------------------------------------
|
| THE CLOCK CLOSURE IS THE COORDINATION POINT, not a sleep. The verifier reads it
| once at the start and once before each batch, so rebinding it lets a test act at
| an exact point inside an open snapshot. tests/AGENTS.md asks for synchronisation
| rather than timing, and this is it.
|
*/

/** Rebind the audit with a clock that runs $atSecondCall before the second batch. */
function verifierWithHook(callable $atSecondCall): void
{
    $calls = 0;

    app()->bind(ProjectionVerifier::class, fn ($app): DatabaseProjectionVerifier => new DatabaseProjectionVerifier(
        $app->make(DatabaseManager::class)->connection(),
        $app->make(VerificationConfiguration::class),
        static function () use (&$calls, $atSecondCall): int {
            $calls++;

            if ($calls === 2) {
                $atSecondCall();
            }

            return 0;
        },
    ));
}

it('reports one snapshot, so a lower id committing mid-audit belongs to the next run', function (): void {
    // THE CLAIM UNDER TEST: the global id is not a commit-order fence. An id below
    // the watermark can still commit after the snapshot opened, and this run must
    // neither see it nor pretend it was checked.
    config(['payments.verification.batch_size' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();
    verifierSeed(merchantId: $merchantId);

    // Allocated BEFORE the last event and left uncommitted, so its id is lower.
    $latePaymentId = (string) Str::uuid7();
    $lockConnection = DB::connection(IntegrationDatabase::LOCK);
    $lockConnection->beginTransaction();
    $lockConnection->table('stored_events')->insert([
        'aggregate_uuid' => $latePaymentId,
        'aggregate_version' => 1,
        'event_version' => 1,
        'event_class' => PaymentInitiated::ALIAS,
        // No envelope, no receipt, no read row: if this row were ever visible to
        // the audit it would produce a finding, which is what makes the control
        // non-vacuous.
        'event_properties' => json_encode([
            'eventId' => (string) Str::uuid7(),
            'paymentId' => $latePaymentId,
            'merchantId' => $merchantId,
            'amountMinor' => 1_250,
            'currency' => 'USD',
            'occurredAt' => CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
        ]),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ]);

    $seededLast = verifierSeed(merchantId: $merchantId);

    verifierWithHook(static function () use ($lockConnection): void {
        $lockConnection->commit();
    });

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_CONSISTENT)
        ->and($report['complete'])->toBeTrue()
        // The watermark is above the late row's id, and the late row is still not
        // part of this run's claim.
        ->and($report['high_watermark'])->toBe($seededLast['stored_event_id'])
        ->and($report['scanned'])->toBe(verifierScanned(2, 2, 2, 2))
        ->and($report['finding_counts'])->toBe([]);

    // NON-VACUOUS: the row really is there, really is corrupt, and a fresh audit
    // finds it. Without this the assertions above would also pass if the insert
    // had silently failed.
    [$secondExit, $secondReport] = verifierRun();

    expect($secondExit)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($secondReport['scanned'][VerificationReport::PASS_CANONICAL_EVENTS])->toBe(3)
        ->and($secondReport['finding_counts'][VerificationFinding::OutboxEnvelopeMissing->value])->toBe(1);
});

it('aborts on a real statement timeout rather than reporting a clean audit', function (): void {
    // AN ACCESS EXCLUSIVE LOCK, NOT A SLEEP. Readers do not block on row locks
    // under MVCC, but they do block on this one, so the audit's payment_outbox
    // read waits until PostgreSQL cancels it as 57014. The lock is held for the
    // whole test, so the wait is unbounded and the cancellation is certain.
    config(['payments.verification.statement_timeout_ms' => 250]);

    verifierSeed();

    $lockConnection = DB::connection(IntegrationDatabase::LOCK);
    $lockConnection->beginTransaction();
    $lockConnection->statement('lock table payment_outbox in access exclusive mode');

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['complete'])->toBeFalse()
        ->and($report['outcome'])->toBe(VerificationReport::OUTCOME_INCOMPLETE)
        ->and($report['reason'])->toBe(DatabaseProjectionVerifier::REASON_STATEMENT_TIMEOUT)
        // It reached the canonical rows and stopped before any reverse pass could
        // claim to have run.
        ->and($report['scanned'])->toBe([VerificationReport::PASS_CANONICAL_EVENTS => 1]);
});

it('reports an incomplete run when its backend is terminated mid-audit', function (): void {
    // THE SESSION-LOSS PATH, CAUSED RATHER THAN SIMULATED. transaction_timeout
    // terminates the backend when it fires; so does pg_terminate_backend, and it
    // does so at an exact point instead of after a wall-clock wait. What matters is
    // the verifier's answer: incomplete, and NOT resumed on a new backend, because
    // the snapshot it was reading no longer exists.
    config(['payments.verification.batch_size' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 3; $i++) {
        verifierSeed(merchantId: $merchantId);
    }

    $auditPid = IntegrationDatabase::backendPid();

    verifierWithHook(static function () use ($auditPid): void {
        DB::connection(IntegrationDatabase::LOCK)
            ->select('select pg_terminate_backend(?)', [$auditPid]);
    });

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['complete'])->toBeFalse()
        ->and($report['reason'])->toBe(DatabaseProjectionVerifier::REASON_DATABASE_ERROR)
        // It did not carry on over a fresh connection: fewer than three canonical
        // events were examined, and no reverse pass started.
        ->and($report['scanned'][VerificationReport::PASS_CANONICAL_EVENTS])->toBeLessThan(3)
        ->and($report['scanned'])->not->toHaveKey(VerificationReport::PASS_PROJECTOR_RECEIPTS);
});

it('confirms this PostgreSQL terminates the session when transaction_timeout fires', function (): void {
    // THE PREMISE THE VERIFIER DOCUMENTS, measured rather than assumed. It is why
    // the audit aborts instead of reconnecting: after this bound fires there is no
    // session left to continue the snapshot on.
    $before = IntegrationDatabase::backendPid();

    DB::beginTransaction();

    $failure = null;

    try {
        DB::statement("set local transaction_timeout = '80ms'");
        DB::select('select pg_sleep(0.4)');
    } catch (QueryException $caught) {
        $failure = $caught;
    } finally {
        IntegrationDatabase::rollbackOpenTransactions();
    }

    expect($failure)->not->toBeNull('transaction_timeout did not fire');

    // A NEW BACKEND ANSWERS THE NEXT QUERY, which is the definition of the old
    // session having been terminated rather than the statement cancelled.
    DB::purge();

    expect(IntegrationDatabase::backendPid())->not->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Query-plan evidence
|--------------------------------------------------------------------------
|
| MEASURED FROM THE STATEMENTS THE AUDIT ACTUALLY ISSUED, captured from the query
| log and replayed through EXPLAIN. Re-typing the SQL here would let the evidence
| drift away from the code the moment a query changed, which is the one failure a
| plan test must not have.
|
| WHAT IT DOES AND DOES NOT CLAIM. It is a claim about plan SHAPE — which
| predicates exist and whether an indexed path is available for them — not about
| speed, and not about behaviour at production volume. A green run on a few
| hundred rows is not a scale claim, and none is made.
|
*/

/** Seed $count consistent payments cheaply, without one round trip per row. */
function verifierBulkSeed(int $count): void
{
    $merchantId = Merchant::factory()->create()->getKey();
    $occurredAt = CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT);

    $canonical = [];
    $envelopes = [];
    $receipts = [];
    $details = [];

    for ($i = 0; $i < $count; $i++) {
        $eventId = (string) Str::uuid7();
        $paymentId = (string) Str::uuid7();
        $payload = json_encode([
            'eventId' => $eventId,
            'paymentId' => $paymentId,
            'merchantId' => $merchantId,
            'amountMinor' => 1_250,
            'currency' => 'USD',
            'occurredAt' => $occurredAt,
        ]);

        $canonical[] = [
            'aggregate_uuid' => $paymentId,
            'aggregate_version' => 1,
            'event_version' => 1,
            'event_class' => PaymentInitiated::ALIAS,
            'event_properties' => $payload,
            'meta_data' => json_encode([]),
            'created_at' => now(),
        ];
        $envelopes[] = [
            'event_id' => $eventId,
            'event_type' => PaymentInitiated::ALIAS,
            'schema_version' => 1,
            'aggregate_uuid' => $paymentId,
            'aggregate_version' => 1,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
            'available_at' => now(),
            'projection_completed_at' => now(),
            'publish_count' => 1,
            'consecutive_transport_failures' => 0,
            'projection_failure_count' => 0,
            'projection_failure_generation' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $receipts[] = [
            'projector_name' => PaymentProjectionConsumer::PROJECTOR_NAME,
            'event_id' => $eventId,
            'created_at' => now(),
        ];
        $details[] = [
            'payment_id' => $paymentId,
            'source_event_id' => $eventId,
            'merchant_id' => $merchantId,
            'amount_minor' => 1_250,
            'currency' => 'USD',
            'status' => PaymentProjectionConsumer::INITIATED_STATUS,
            'aggregate_version' => 1,
            'initiated_at' => $occurredAt,
        ];
    }

    foreach (array_chunk($canonical, 100) as $chunk) {
        DB::table('stored_events')->insert($chunk);
    }

    foreach (array_chunk($envelopes, 100) as $chunk) {
        DB::table('payment_outbox')->insert($chunk);
    }

    foreach (array_chunk($receipts, 100) as $chunk) {
        DB::table('payment_projector_receipts')->insert($chunk);
    }

    foreach (array_chunk($details, 100) as $chunk) {
        DB::table('payment_details')->insert($chunk);
    }
}

/**
 * Every SELECT the audit issued, mapped to its EXPLAIN plan as JSON text.
 *
 * @return array<string, string>
 */
function verifierPlans(?string $sessionSetting = null): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    verifierRun();

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $plans = [];

    foreach ($log as $entry) {
        $sql = trim((string) $entry['query']);

        if (! str_starts_with(strtolower($sql), 'select')) {
            continue;
        }

        // Keep the first bindings for each query shape, not the empty terminal
        // page that can replace a real batch and make plan evidence vacuous.
        if (isset($plans[$sql])) {
            continue;
        }

        DB::beginTransaction();

        try {
            if ($sessionSetting !== null) {
                DB::statement($sessionSetting);
            }

            $explained = DB::selectOne('explain (format json) '.$sql, $entry['bindings']);
            $plans[$sql] = (string) ($explained->{'QUERY PLAN'} ?? '');
        } finally {
            DB::rollBack();
        }
    }

    return $plans;
}

/**
 * The one plan whose statement contains $needle.
 *
 * IT REFUSES AN AMBIGUOUS NEEDLE RATHER THAN TAKING THE FIRST MATCH, and that is
 * not caution. An earlier version returned the first hit, and `payment_outbox
 * envelope` matched the RECEIPTS query — which joins that table under that alias —
 * so the envelope pass silently went unasserted while the receipts pass was
 * checked twice. A plan test that quietly examines the wrong statement is worse
 * than no plan test.
 *
 * @param  array<string, string>  $plans
 */
function verifierPlanFor(array $plans, string $needle, string $comparison): string
{
    $matched = array_filter(
        $plans,
        static fn (string $sql): bool => str_contains($sql, $needle) && str_contains($sql, $comparison),
        ARRAY_FILTER_USE_KEY,
    );

    if (count($matched) !== 1) {
        throw new RuntimeException(sprintf(
            "Expected exactly one audit statement containing '%s', found %d.",
            $needle,
            count($matched),
        ));
    }

    return (string) reset($matched);
}

/**
 * The FROM clause of each reverse pass, which is what makes the needle unique:
 * the receipts pass also joins payment_outbox under the same alias.
 *
 * @return list<string>
 */
function verifierReversePasses(): array
{
    return [
        'from payment_projector_receipts receipts',
        'from payment_details details',
        'from payment_outbox envelope',
    ];
}

it('resolves every reverse pass without touching the unindexed JSON payload', function (): void {
    // THE MEASURED-INDEX GATE. An orphan check that asked canonical history about
    // the durable event id would have to read event_properties, which has no index
    // and cannot get one without a migration decision. Each reverse pass resolves
    // on an aggregate identity column instead, so the plans below must not mention
    // that column at all.
    verifierBulkSeed(200);
    DB::statement('analyze stored_events, payment_outbox, payment_projector_receipts, payment_details');

    $plans = verifierPlans();

    foreach (verifierReversePasses() as $pass) {
        foreach ([' >= ?', ' > ?'] as $comparison) {
            expect(verifierPlanFor($plans, $pass, $comparison))
                ->not->toContain('event_properties', "the {$pass} pass reached for the JSON payload");
        }
    }
});

it('has an indexed path into canonical history for every reverse pass', function (): void {
    // AVAILABILITY, NOT THE PLANNER'S CHOICE. At fixture volume PostgreSQL may
    // rightly prefer a sequential scan of a tiny table, so asserting the natural
    // plan would measure the fixture rather than the schema. Disabling seqscan asks
    // the question that survives scale: can this predicate be served by an index
    // that already exists? If it could not, an index would be a migration decision
    // rather than a detail — which is exactly what this test is here to catch.
    verifierBulkSeed(200);
    DB::statement('analyze stored_events, payment_outbox, payment_projector_receipts, payment_details');

    $plans = verifierPlans('set local enable_seqscan = off');

    foreach (verifierReversePasses() as $pass) {
        foreach ([' >= ?', ' > ?'] as $comparison) {
            expect(verifierPlanFor($plans, $pass, $comparison))->toMatch(
                '/stored_events_aggregate_uuid_index|stored_events_aggregate_uuid_aggregate_version_unique/',
                "the {$pass} pass has no indexed path into stored_events",
            );
        }
    }
});

it('reads the JSON payload in exactly one bounded aggregate and nowhere else', function (): void {
    // The duplicate-id check groups by the payload's event id without a dedicated
    // index: it is one pass over the Payments slice of
    // history, the same work the canonical walk already does, and its result is
    // bounded by a limit. That it is the only such statement is the property worth
    // pinning, because a second one would be a per-row JSON lookup in disguise.
    verifierBulkSeed(50);

    $plans = verifierPlans();

    $touching = array_keys(array_filter(
        $plans,
        static fn (string $plan): bool => str_contains($plan, 'event_properties'),
    ));

    expect($touching)->toHaveCount(1)
        ->and($touching[0])->toContain("event_properties->>'eventId'")
        ->and($touching[0])->toContain('limit');
});
