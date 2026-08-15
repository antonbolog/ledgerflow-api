<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Modules\Payments\Domain\Payment\Exceptions\PaymentAlreadyInitiated;
use Modules\Payments\Domain\Payment\PaymentAggregate;
use Modules\Payments\Infrastructure\Projectors\PaymentOutboxWriter;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Spatie\EventSourcing\StoredEvents\StoredEvent;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Payment initiation persistence contract
|--------------------------------------------------------------------------
|
| The integration point of T5: a real persist() through Spatie against the real
| schema. Everything above this file is either framework-free (the domain) or
| catalog-only (the schema); this is where the two meet.
|
| WHAT THIS FILE DELIBERATELY DOES NOT DO. It ships no outbox writer, registers
| no projector, enqueues nothing, and reserves no idempotency receipt. The outbox
| derivation at the bottom runs INSIDE THE TEST, from values measured off a
| really-persisted event, because what T5 owes is proof that payment_outbox is
| satisfiable from a real PaymentInitiated — not a production writer. T6 lifts
| that mapping into its projector.
|
| SCOPE EVERY stored_events READ BY event_class. The T3/T4 spikes commit real
| rows into this same table in ledgerflow_test and delete them in
| SpikeDatabase::teardown(). A bare sole() would couple these tests to another
| suite's teardown hygiene and surface a leak as a confusing "multiple records"
| failure here. Filtering on the production alias — which no spike fixture ever
| writes — keeps them order-independent and exactly as strict.
|
*/

/**
 * One really-persisted payment, returning the recorded event.
 *
 * IT CAPTURES THE EVENT BEFORE persist(). persistWithoutApplyingToEventHandlers()
 * calls getAndClearRecordedEvents(), so getRecordedEvents() returns [] once
 * persistence returns. Capturing afterwards yields an empty array and the
 * derivation test dies on an undefined index instead of asserting anything. The
 * captured object is the same instance the repository then stamps with
 * stored-event-id, so READING it after persist() is correct; only FINDING it
 * afterwards is not.
 */
function persistOnePayment(
    ?string $paymentId = null,
    string $occurredAt = '2026-08-14T10:11:12.123456+00:00',
): PaymentInitiated {
    $paymentId ??= (string) Str::uuid7();
    $merchantId = Merchant::factory()->create()->getKey();

    $aggregate = PaymentAggregate::retrieve($paymentId)->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: $paymentId,
        merchantId: $merchantId,
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: $occurredAt,
    );

    // BEFORE persist(). See the note above.
    $event = $aggregate->getRecordedEvents()[0];

    DB::transaction(fn () => $aggregate->persist());

    return $event;
}

/** Every stored_events read in this file goes through here. */
function t5StoredEvent(): object
{
    return DB::table('stored_events')->where('event_class', PaymentInitiated::ALIAS)->sole();
}

it('declares exactly one event alias and keeps handler exceptions visible', function (): void {
    expect(config('event-sourcing.event_class_map'))
        ->toBe([PaymentInitiated::ALIAS => PaymentInitiated::class])
        ->and(config('event-sourcing.catch_exceptions'))->toBeFalse()
        ->and(config('event-sourcing.enforce_event_class_map'))->toBeFalse();
});

it('pins catch_exceptions to a literal false in the application file itself', function (): void {
    // THE MERGED RUNTIME VALUE CANNOT CARRY THIS. config('…catch_exceptions') is
    // false whether we declare it or not, because the vendor default is false
    // too — so a test that only reads config() passes after someone deletes the
    // key, and passes again after someone replaces it with
    // env('EVENT_PROJECTOR_CATCH_EXCEPTIONS', false), which is false locally and
    // true in whatever environment sets that variable.
    //
    // Projectionist copies this value into a private property when its singleton
    // is constructed, so nothing can correct it later. This test therefore reads
    // OUR file, not the merged configuration.
    $path = base_path('config/event-sourcing.php');

    // require, not config(): this returns our array alone, unmerged, so a missing
    // key is visibly missing rather than backfilled by the vendor default.
    $declared = require $path;

    expect($declared)->toHaveKey('catch_exceptions')
        ->and($declared['catch_exceptions'])->toBeFalse();

    // No env() CALL in the file. A value check alone cannot catch the env()
    // form, because it also evaluates to false wherever the variable is unset.
    //
    // Token scan rather than a substring search: the file's own comment explains
    // that this value is "NOT FROM env()", and a naive str_contains would match
    // that prose. Comments are not code, and the rule is about code.
    $tokens = token_get_all((string) file_get_contents($path));
    $envCalls = 0;

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'env') {
            continue;
        }

        for ($i = $index + 1; $i < count($tokens); $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($tokens[$i] === '(') {
                $envCalls++;
            }

            break;
        }
    }

    expect($envCalls)->toBe(0, 'config/event-sourcing.php must not delegate any value to env().');
});

it('maps every production storable event, discovered rather than listed', function (): void {
    // DISCOVERY, not a hand-kept list. Exact equality on the map catches someone
    // editing the map; only a scan catches someone adding a SECOND event class
    // and never mapping it — which would silently store a fully-qualified class
    // name in stored_events.event_class and defeat the aliasing decision.
    //
    // It scans Modules/*/app only, so the deliberately unmapped T3/T4 spike
    // fixtures under Modules/*/tests stay invisible to it. That is exactly why
    // enforce_event_class_map can remain false.
    $discovered = [];

    foreach (Finder::create()->files()->name('*.php')->in(base_path('Modules'))->path('app/') as $file) {
        preg_match('/^namespace\s+(.+?);/m', (string) $file->getContents(), $namespace);

        if ($namespace === []) {
            continue;
        }

        $class = $namespace[1].'\\'.$file->getBasename('.php');

        if (class_exists($class) && is_subclass_of($class, ShouldBeStored::class)) {
            $discovered[] = $class;
        }
    }

    // Non-vacuous: if the scan finds nothing the equality below would pass
    // against an empty map, so prove the scanner sees the event we know exists.
    expect($discovered)->toContain(PaymentInitiated::class);

    expect($discovered)->toEqualCanonicalizing(array_values(config('event-sourcing.event_class_map')));
});

it('persists one stored event carrying the alias, identity and version contract', function (): void {
    $paymentId = (string) Str::uuid7();

    DB::transaction(fn () => PaymentAggregate::retrieve($paymentId)->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: $paymentId,
        merchantId: Merchant::factory()->create()->getKey(),
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
    )->persist());

    $stored = t5StoredEvent();

    // The alias, not the FQCN — that is what lets the namespace move later.
    expect($stored->event_class)->toBe('payments.payment_initiated');
    expect($stored->event_class)->not->toContain('Modules\\');

    expect($stored->aggregate_uuid)->toBe($paymentId)
        ->and((int) $stored->aggregate_version)->toBe(1)
        ->and((int) $stored->event_version)->toBe(1);
});

it('keeps the durable payload to exactly the six business fields at full precision', function (): void {
    // Spatie's ObjectNormalizer filters the ShouldBeStored attributes only when
    // the child declares no property of that name. This pins the durable key set
    // so a future property cannot widen it unnoticed, and proves microseconds
    // survive serialisation.
    persistOnePayment(occurredAt: '2026-08-14T10:11:12.123456+00:00');

    $payload = json_decode((string) t5StoredEvent()->event_properties, true);

    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'paymentId', 'merchantId', 'amountMinor', 'currency', 'occurredAt',
    ])->and($payload['occurredAt'])->toBe('2026-08-14T10:11:12.123456+00:00');
});

it('reads the event back out of the store unchanged', function (): void {
    // "Unchanged" means ALL SIX business fields, compared against the object that
    // was actually persisted rather than against literals. Checking a subset lets
    // a serializer that mangles an unchecked field pass, and comparing to literals
    // only proves the fixture matches itself.
    $original = persistOnePayment();

    // A fresh query, not the instance persist() held: EloquentStoredEvent returns
    // its cached originalEvent when it has one, so reusing that instance would
    // assert on the object we put in rather than on what came back out. This is
    // the only test that exercises Symfony's ability to reconstruct a
    // readonly-promoted event through the serializer.
    $event = EloquentStoredEvent::query()
        ->where('event_class', PaymentInitiated::ALIAS)
        ->sole()
        ->toStoredEvent()
        ->event;

    expect($event)->toBeInstanceOf(PaymentInitiated::class);

    // A genuinely different object. If this were the cached instance the test
    // would prove nothing about deserialisation at all.
    expect($event)->not->toBe($original);

    // toBe() is strict, so this also pins the TYPES across the JSON round trip:
    // merchantId and amountMinor must come back as int, not as numeric strings.
    expect($event->eventId)->toBe($original->eventId)
        ->and($event->paymentId)->toBe($original->paymentId)
        ->and($event->merchantId)->toBe($original->merchantId)
        ->and($event->amountMinor)->toBe($original->amountMinor)
        ->and($event->currency)->toBe($original->currency)
        ->and($event->occurredAt)->toBe($original->occurredAt);
});

it('restores initiated state when the aggregate is replayed from the event store', function (): void {
    // THE ONLY TEST THAT PROVES REPLAY WORKS END TO END. The unit suite applies
    // the event in memory; this one persists it, throws the object away, and
    // rebuilds a fresh aggregate from stored_events through Spatie's
    // reconstitution — which is also the path that reads `snapshots`.
    //
    // A second initiate() throwing is the observable proof that
    // applyPaymentInitiated() ran during replay: nothing else sets that flag.
    $paymentId = (string) Str::uuid7();
    $merchantId = Merchant::factory()->create()->getKey();

    persistOnePayment(paymentId: $paymentId);

    $replayed = PaymentAggregate::retrieve($paymentId);

    expect(fn () => $replayed->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: $paymentId,
        merchantId: $merchantId,
        amountMinor: 500,
        currency: 'USD',
        occurredAt: '2026-08-14T11:00:00.000000+00:00',
    ))->toThrow(PaymentAlreadyInitiated::class);
});

it('refuses a paymentId that disagrees with the bound aggregate uuid', function (): void {
    // The domain guard is unreachable from the framework-free unit tests, which
    // build the aggregate with `new` and therefore leave its uuid empty. Only a
    // container-resolved, uuid-bound aggregate can exercise it.
    $paymentId = (string) Str::uuid7();

    expect(fn () => PaymentAggregate::retrieve($paymentId)->initiate(
        eventId: (string) Str::uuid7(),
        paymentId: (string) Str::uuid7(),
        merchantId: Merchant::factory()->create()->getKey(),
        amountMinor: 1_250,
        currency: 'USD',
        occurredAt: '2026-08-14T10:11:12.123456+00:00',
    ))->toThrow(InvalidPaymentInitiation::class);
});

it('derives every required outbox column from a really-persisted event', function (): void {
    // THIS IS WHY T5 DOES NOT SHIP THE OUTBOX WRITER. The writer is step 5 of
    // T6's atomic write. What T5 owes is proof that payment_outbox is satisfiable
    // from a real PaymentInitiated — so the derivation runs here, in a test,
    // against values measured off the event rather than invented by a fixture.
    //
    // T6 SLICE 2 REGISTERED THE PRODUCTION WRITER, so persist() now fills
    // payment_outbox on its own and the by-hand insert below would collide on the
    // event_id primary key. Removing the projector for the duration of this test
    // keeps T5's claim exactly what it always was — the envelope is DERIVABLE from a
    // bare persist() — rather than silently turning it into a second test of T6's
    // writer, which InitiatePaymentWriteTest already covers.
    resolve(Projectionist::class)->withoutEventHandler(PaymentOutboxWriter::class);

    $event = persistOnePayment();
    $stored = t5StoredEvent();
    $now = CarbonImmutable::now();

    DB::table('payment_outbox')->insert([
        'event_id' => $event->eventId,
        'event_type' => StoredEvent::getEventClass($event::class),
        'schema_version' => $event->eventVersion(),
        'aggregate_uuid' => $event->aggregateRootUuid(),
        'aggregate_version' => $event->aggregateRootVersion(),
        'payload' => $stored->event_properties,
        'occurred_at' => $event->occurredAt,
        'available_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $row = DB::table('payment_outbox')->sole();

    // EVERY column traced back to a real source — the event object or the stored
    // event — never to a literal. A literal only proves the fixture matches
    // itself; this pinned the mapping T6 later lifted into PaymentOutboxWriter.
    //
    // Column defaults and nullability are NOT re-asserted here: Slice 2's schema
    // contract owns those, and duplicating them would mean two places to update.
    expect($row->event_id)->toBe($event->eventId)
        ->and($row->event_type)->toBe(StoredEvent::getEventClass($event::class))
        ->and($row->event_type)->toBe($stored->event_class)
        ->and((int) $row->schema_version)->toBe($event->eventVersion())
        ->and($row->aggregate_uuid)->toBe($event->aggregateRootUuid())
        ->and($row->aggregate_uuid)->toBe($stored->aggregate_uuid)
        ->and((int) $row->aggregate_version)->toBe($event->aggregateRootVersion())
        ->and((int) $row->aggregate_version)->toBe((int) $stored->aggregate_version);

    // SEMANTIC time comparison, not a substring. PostgreSQL renders timestamptz
    // in its own style ('2026-08-14 10:11:12.123456+00'), so parsing it back and
    // re-formatting canonically is what proves the instant survived the round
    // trip — and it catches a truncated microsecond, a shifted zone, or a column
    // that was handed now() instead of the event's own time. A contains('.123456')
    // check would pass on any of those as long as those six digits appeared
    // somewhere.
    expect(CarbonImmutable::parse($row->occurred_at)->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT))
        ->toBe($event->occurredAt);

    // Envelope equals stored event, compared as jsonb — exactly what M5's verifier
    // will do. Bound to the row we captured rather than a `limit 1` subquery,
    // which would pick an arbitrary row if a spike ever leaked one.
    expect(DB::table('payment_outbox')
        ->whereRaw('payload = ?::jsonb', [$stored->event_properties])
        ->count())->toBe(1);
});
