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

/**
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

function verifierRun(): array
{
    $exitCode = Artisan::call('payments:projections:verify');

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    return [$exitCode, $decoded];
}

/**
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

    expect(array_keys($report['classification_counts']))->toBe([
        'consistent', 'in_flight', 'delivery_overdue', 'exhausted', 'inconsistent',
    ]);
});

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

it('fails a canonical event whose outbox envelope does not exist', function (): void {
    verifierSeed(withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and(verifierFindings($report))->toBe([VerificationFinding::OutboxEnvelopeMissing->value])
        ->and($report['classification_counts'][VerificationClassification::Inconsistent->value])->toBe(1);
});

it('never lets recent age hide a missing envelope', function (): void {
    // Grace must not cover a missing envelope: the event and its envelope commit together.
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

it('reports a Payments alias it cannot decode instead of skipping it', function (): void {
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

it('walks more events than one batch holds without repeating or missing any', function (): void {
    config(['payments.verification.batch_size' => 2]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 5; $i++) {
        verifierSeed(merchantId: $merchantId);
    }

    $ids = DB::table('stored_events')->orderBy('id')->pluck('id');
    DB::table('stored_events')->where('id', $ids[2])->delete();

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCONSISTENT)
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
    config(['payments.verification.max_payload_bytes' => 32]);

    verifierSeed(withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);

    [$exitCode, $report] = verifierRun();

    expect($exitCode)->toBe(VerificationReport::EXIT_INCOMPLETE)
        ->and($report['complete'])->toBeFalse()
        ->and($report['outcome'])->toBe(VerificationReport::OUTCOME_INCOMPLETE)
        ->and($report['reason'])->toBe('payload_exceeds_inspection_limit');
});

it('validates the shipped configuration, so the defaults are the ones under test', function (): void {
    $config = VerificationConfiguration::fromArray((array) config('payments.verification'));

    expect($config->batchSize)->toBe(100)
        ->and($config->exampleLimit)->toBe(50)
        ->and($config->graceSeconds)->toBe(30)
        ->and($config->statementTimeoutMs)->toBe(2_000)
        ->and($config->transactionTimeoutMs)->toBe(120_000)
        ->and($config->maxPayloadBytes)->toBe(65_536);
});

it('stops on its work budget and keeps the findings it had already made', function (): void {
    config(['payments.verification.batch_size' => 1]);

    $merchantId = Merchant::factory()->create()->getKey();

    for ($i = 0; $i < 2; $i++) {
        verifierSeed(merchantId: $merchantId, withEnvelope: false, withReceipt: false, withReadRow: false, withMarker: false);
    }

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

    // Equal counts would survive an update; an unchanged xmin would not.
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

    // Only this counter is non-vacuous: an unguarded run reports the same fields after PostgreSQL's 25001.
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

it('finds a projector receipt no canonical event accounts for', function (): void {
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
    verifierSeed(envelopeOverrides: ['aggregate_version' => 7]);

    [, $report] = verifierRun();

    expect(verifierFindings($report))->not->toContain(VerificationFinding::ProjectorReceiptOrphaned->value);
});

it('reports duplicate canonical event ids that no single batch could see together', function (): void {
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
        ->and($report['high_watermark'])->toBe($seededLast['stored_event_id'])
        ->and($report['scanned'])->toBe(verifierScanned(2, 2, 2, 2))
        ->and($report['finding_counts'])->toBe([]);

    [$secondExit, $secondReport] = verifierRun();

    expect($secondExit)->toBe(VerificationReport::EXIT_INCONSISTENT)
        ->and($secondReport['scanned'][VerificationReport::PASS_CANONICAL_EVENTS])->toBe(3)
        ->and($secondReport['finding_counts'][VerificationFinding::OutboxEnvelopeMissing->value])->toBe(1);
});

it('aborts on a real statement timeout rather than reporting a clean audit', function (): void {
    // ACCESS EXCLUSIVE, because MVCC readers do not block on row locks.
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
        ->and($report['scanned'])->toBe([VerificationReport::PASS_CANONICAL_EVENTS => 1]);
});

it('reports an incomplete run when its backend is terminated mid-audit', function (): void {
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
        ->and($report['scanned'][VerificationReport::PASS_CANONICAL_EVENTS])->toBeLessThan(3)
        ->and($report['scanned'])->not->toHaveKey(VerificationReport::PASS_PROJECTOR_RECEIPTS);
});

it('confirms this PostgreSQL terminates the session when transaction_timeout fires', function (): void {
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

    // A new backend pid distinguishes a terminated session from a cancelled statement.
    DB::purge();

    expect(IntegrationDatabase::backendPid())->not->toBe($before);
});

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

        // Keep the first bindings: the empty terminal page would make the plan evidence vacuous.
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
 * FROM clauses, because the receipts pass also joins payment_outbox under the envelope alias.
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
    // Reverse passes must resolve on aggregate identity, never on the unindexed event_properties payload.
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
    // Seqscan disabled: asks whether an index path exists, not what the planner prefers at fixture volume.
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
    // Only the bounded duplicate-id aggregate may read the payload; a second reader would be a per-row JSON lookup.
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
