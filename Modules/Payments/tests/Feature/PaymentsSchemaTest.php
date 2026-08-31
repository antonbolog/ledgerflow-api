<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;

/*
|--------------------------------------------------------------------------
| Payments persistence schema
|--------------------------------------------------------------------------
|
| Asserts the LIVE shape of the four tables T5 creates and the two T8 adds, read
| from the PostgreSQL catalogs rather than from the migration source. A test that
| re-read the migration would only prove the file says what it says.
|
| Read from CATALOG SEMANTICS, not rendered SQL. pg_get_expr and pg_indexes
| re-render expressions with the server's own parenthesisation and spacing,
| neither of which is part of this contract; asserting against those strings
| tests the formatter as much as the schema and breaks on a server upgrade that
| changed nothing we care about. Predicates are normalised before comparison and
| index columns are read from pg_index/pg_attribute.
|
| The helpers are local on purpose: a few small queries are cheaper than a new
| autoload seam, and a helper shared across Pest files depends on file load
| order.
|
| EVERY INSERT EXPECTED TO VIOLATE A CONSTRAINT IS WRAPPED IN DB::transaction().
| PostgreSQL aborts the entire transaction on a failed statement — every later
| statement returns 25P02 "current transaction is aborted" until rollback — and
| RefreshDatabase already has each test inside one. Laravel implements a nested
| transaction as SAVEPOINT + ROLLBACK TO SAVEPOINT, so the wrapper confines the
| failure and leaves the outer transaction usable for the assertions that follow.
| The original exception still propagates, so the constraint-name assertions are
| unaffected.
|
*/

/** Every table Payments owns, in the order the migrations create them. */
const PAYMENTS_TABLES = [
    'payment_idempotency_receipts',
    'payment_outbox',
    'payment_projector_receipts',
    'payment_details',
];

/** The complete set of Payments-owned timestamp columns, qualified. */
const T5_TIMESTAMP_COLUMNS = [
    'payment_idempotency_receipts.created_at',
    'payment_idempotency_receipts.updated_at',
    'payment_outbox.available_at',
    'payment_outbox.created_at',
    'payment_outbox.exhausted_at',
    'payment_outbox.last_published_at',
    'payment_outbox.occurred_at',
    'payment_outbox.projection_completed_at',
    'payment_outbox.updated_at',
    'payment_projector_receipts.created_at',
    'payment_details.initiated_at',
];

/**
 * @return array<string, array{type: string, nullable: bool, precision: int|null, default: string|null, length: int|null}>
 */
function t5Columns(string $table): array
{
    $rows = DB::select(
        'select column_name, data_type, is_nullable, datetime_precision,
                column_default, character_maximum_length
           from information_schema.columns
          where table_schema = current_schema() and table_name = ?',
        [$table],
    );

    return collect($rows)->keyBy('column_name')->map(fn (object $r): array => [
        'type' => (string) $r->data_type,
        'nullable' => $r->is_nullable === 'YES',
        'precision' => $r->datetime_precision === null ? null : (int) $r->datetime_precision,
        'default' => $r->column_default === null ? null : (string) $r->column_default,
        'length' => $r->character_maximum_length === null ? null : (int) $r->character_maximum_length,
    ])->all();
}

/**
 * Index shape from the catalog: ordered column list, uniqueness, partiality and
 * the predicate expression. Nothing here depends on how the server renders DDL.
 *
 * @return array<string, array{columns: string, unique: bool, partial: bool, predicate: string|null}>
 */
function t5IndexCatalog(string $table): array
{
    $rows = DB::select(
        "select i.relname as name,
                ix.indisunique::int as is_unique,
                (ix.indpred is not null)::int as is_partial,
                pg_get_expr(ix.indpred, ix.indrelid) as predicate,
                (select string_agg(a.attname, ',' order by k.ord)
                   from unnest(ix.indkey) with ordinality as k(attnum, ord)
                   join pg_attribute a
                     on a.attrelid = ix.indrelid and a.attnum = k.attnum) as columns
           from pg_index ix
           join pg_class i on i.oid = ix.indexrelid
           join pg_class t on t.oid = ix.indrelid
           join pg_namespace n on n.oid = t.relnamespace
          where t.relname = ? and n.nspname = current_schema()",
        [$table],
    );

    return collect($rows)->keyBy('name')->map(fn (object $r): array => [
        'columns' => (string) $r->columns,
        'unique' => (int) $r->is_unique === 1,
        'partial' => (int) $r->is_partial === 1,
        'predicate' => $r->predicate === null ? null : (string) $r->predicate,
    ])->all();
}

/**
 * Parentheses and whitespace are the server's, not ours. Stripping both leaves
 * the LOGIC, so `AND` versus `OR` is what the assertion actually compares.
 */
function t5NormalisePredicate(?string $predicate): string
{
    return trim((string) preg_replace('/\s+/', ' ', str_replace(['(', ')'], '', (string) $predicate)));
}

/**
 * One complete receipt row.
 *
 * FRESH id, payment_id AND key hash on every call unless overridden. This is
 * load-bearing rather than hygiene: a receipt carries three unique keys, so a
 * reused surrogate makes the PRIMARY KEY fire first and a uniqueness test then
 * passes whether or not the key it means to prove exists.
 */
function insertReceipt(
    ?int $merchantId = null,
    ?string $keyHash = null,
    ?string $paymentId = null,
    string $responseBody = '{"status":"initiated"}',
): string {
    $id = (string) Str::uuid7();
    $paymentId ??= (string) Str::uuid7();
    $keyHash ??= hash('sha256', $id);
    $now = now();

    DB::table('payment_idempotency_receipts')->insert([
        'id' => $id,
        'merchant_id' => $merchantId ?? Merchant::factory()->create()->getKey(),
        'idempotency_key_hash' => $keyHash,
        'request_fingerprint' => hash('sha256', $responseBody),
        'payment_id' => $paymentId,
        'response_status' => 202,
        'response_body' => $responseBody,
        'response_location' => "/api/payments/{$paymentId}",
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/** One complete outbox row. Fresh event_id and aggregate_uuid unless overridden. */
function insertOutboxRow(
    ?string $eventId = null,
    ?string $aggregateUuid = null,
    int $aggregateVersion = 1,
): string {
    $eventId ??= (string) Str::uuid7();
    $aggregateUuid ??= (string) Str::uuid7();
    $now = now();

    DB::table('payment_outbox')->insert([
        'event_id' => $eventId,
        'event_type' => 'payments.payment_initiated',
        'schema_version' => 1,
        'aggregate_uuid' => $aggregateUuid,
        'aggregate_version' => $aggregateVersion,
        'payload' => json_encode(['paymentId' => $aggregateUuid, 'amountMinor' => 1_250]),
        'occurred_at' => $now,
        'available_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $eventId;
}

/** One stored event, shaped the way Spatie's repository writes them. */
function insertStoredEvent(
    string $aggregateUuid,
    int $aggregateVersion,
    string $eventClass = 'payments.payment_initiated',
): void {
    DB::table('stored_events')->insert([
        'aggregate_uuid' => $aggregateUuid,
        'aggregate_version' => $aggregateVersion,
        'event_version' => 1,
        'event_class' => $eventClass,
        'event_properties' => json_encode(['paymentId' => $aggregateUuid]),
        'meta_data' => json_encode([]),
        'created_at' => now(),
    ]);
}

it('gives stored_events the exact vendor column set and concurrency key', function (): void {
    // Not a checksum against the vendor stub: a production migration is immutable
    // project history and must not fail because a later package version edits a
    // file we no longer read. What matters is the live shape our code uses.
    $columns = t5Columns('stored_events');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'aggregate_uuid', 'aggregate_version', 'event_version',
        'event_class', 'event_properties', 'meta_data', 'created_at',
    ])
        ->and($columns['aggregate_uuid'])->toMatchArray(['type' => 'uuid', 'nullable' => true])
        ->and($columns['aggregate_version'])->toMatchArray(['type' => 'bigint', 'nullable' => true])
        ->and($columns['event_version'])->toMatchArray(['type' => 'smallint', 'nullable' => false])
        ->and($columns['event_class'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 255])
        ->and($columns['event_properties'])->toMatchArray(['type' => 'jsonb', 'nullable' => false])
        ->and($columns['meta_data'])->toMatchArray(['type' => 'jsonb', 'nullable' => false])
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp without time zone', 'nullable' => false]);

    // The vendor keeps this column at second precision and it is the package's
    // own. Business time lives in the payload, not here.
    expect($columns['created_at']['precision'])->toBe(0);

    $indexes = t5IndexCatalog('stored_events');

    expect(array_keys($indexes))->toEqualCanonicalizing([
        'stored_events_pkey',
        'stored_events_aggregate_uuid_index',
        'stored_events_event_class_index',
        'stored_events_aggregate_uuid_aggregate_version_unique',
    ])
        ->and($indexes['stored_events_aggregate_uuid_aggregate_version_unique'])
        ->toMatchArray(['columns' => 'aggregate_uuid,aggregate_version', 'unique' => true]);
});

it('refuses a second stored event at the same aggregate version even under another event class', function (): void {
    // BEHAVIOURAL, not structural. A three-column unique that also included
    // event_class would still be named and reported as a unique index on
    // aggregate_uuid, so only an insert can tell the two apart: with the widened
    // key this second row would be accepted and T6's optimistic concurrency would
    // silently stop working.
    $aggregateUuid = (string) Str::uuid7();

    insertStoredEvent($aggregateUuid, 1);

    expect(fn () => DB::transaction(fn () => insertStoredEvent($aggregateUuid, 1, 'payments.payment_authorized')))
        ->toThrow(UniqueConstraintViolationException::class, 'stored_events_aggregate_uuid_aggregate_version_unique');

    // A different version on the same aggregate is the normal case and must pass.
    insertStoredEvent($aggregateUuid, 2);

    expect(DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->count())->toBe(2);
});

it('gives snapshots the exact vendor column set and its lookup index', function (): void {
    // Nothing in M1-M5 writes this table; AggregateRoot::reconstituteFromEvents()
    // READS it on every retrieve(), which is the whole reason it exists. Its shape
    // still has to be right, because the read is a real query.
    $columns = t5Columns('snapshots');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'aggregate_uuid', 'aggregate_version', 'state', 'created_at', 'updated_at',
    ])
        ->and($columns['aggregate_uuid'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['aggregate_version'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['state'])->toMatchArray(['type' => 'jsonb', 'nullable' => false])
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp without time zone', 'nullable' => true])
        ->and($columns['updated_at'])->toMatchArray(['type' => 'timestamp without time zone', 'nullable' => true]);

    $indexes = t5IndexCatalog('snapshots');

    expect(array_keys($indexes))->toEqualCanonicalizing(['snapshots_pkey', 'snapshots_aggregate_uuid_index'])
        ->and($indexes['snapshots_aggregate_uuid_index'])
        ->toMatchArray(['columns' => 'aggregate_uuid', 'unique' => false]);
});

it('gives payment_idempotency_receipts its exact documented shape', function (): void {
    $columns = t5Columns('payment_idempotency_receipts');

    // The exact set is the point: an added persistence column fails here, which
    // is what keeps a raw key, a token or a PAN from acquiring somewhere to live.
    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'merchant_id', 'idempotency_key_hash', 'request_fingerprint',
        'payment_id', 'response_status', 'response_body', 'response_location',
        'created_at', 'updated_at',
    ])
        ->and($columns['id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['merchant_id'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        // char(64), not varchar: a SHA-256 hex digest is always exactly 64 chars.
        ->and($columns['idempotency_key_hash'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 64])
        ->and($columns['request_fingerprint'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 64])
        ->and($columns['payment_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['response_status'])->toMatchArray(['type' => 'smallint', 'nullable' => false])
        // TEXT, never JSONB: replay must return the exact bytes the first caller
        // received, and jsonb reorders keys and strips whitespace.
        ->and($columns['response_body'])->toMatchArray(['type' => 'text', 'nullable' => false])
        ->and($columns['response_location'])->toMatchArray(['type' => 'text', 'nullable' => false]);

    $indexes = t5IndexCatalog('payment_idempotency_receipts');

    expect(array_keys($indexes))->toEqualCanonicalizing([
        'payment_idempotency_receipts_pkey',
        'payment_receipts_merchant_key_unique',
        'payment_receipts_payment_id_unique',
        'payment_receipts_merchant_payment_index',
    ])
        ->and($indexes['payment_receipts_merchant_key_unique'])
        ->toMatchArray(['columns' => 'merchant_id,idempotency_key_hash', 'unique' => true])
        ->and($indexes['payment_receipts_payment_id_unique'])
        ->toMatchArray(['columns' => 'payment_id', 'unique' => true])
        // Leads with merchant_id, which is why no standalone FK index exists.
        ->and($indexes['payment_receipts_merchant_payment_index'])
        ->toMatchArray(['columns' => 'merchant_id,payment_id', 'unique' => false]);
});

it('gives payment_outbox its exact documented shape', function (): void {
    $columns = t5Columns('payment_outbox');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'event_id', 'event_type', 'schema_version', 'aggregate_uuid', 'aggregate_version',
        'payload', 'occurred_at', 'available_at', 'last_published_at',
        'projection_completed_at', 'exhausted_at', 'publish_count',
        'consecutive_transport_failures', 'projection_failure_count',
        'projection_failure_generation',
        'last_transport_error', 'last_projection_error', 'created_at', 'updated_at',
    ])
        ->and($columns['event_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['event_type'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 255])
        ->and($columns['schema_version'])->toMatchArray(['type' => 'smallint', 'nullable' => false])
        ->and($columns['aggregate_uuid'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['aggregate_version'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['payload'])->toMatchArray(['type' => 'jsonb', 'nullable' => false]);

    // THREE SEPARATE COUNTERS, each defaulting to zero. The specification's whole
    // failure model rests on this: a transport outage must never consume poison
    // budget, and one shared counter cannot express that.
    // T8 adds a FOURTH zero-defaulted integer, and it is not a counter: it is the
    // monotonic generation fence a failure recorder captures under the projection
    // lock so a late write cannot charge a newer operator-retry cycle.
    foreach ([
        'publish_count',
        'consecutive_transport_failures',
        'projection_failure_count',
        'projection_failure_generation',
    ] as $counter) {
        expect($columns[$counter])->toMatchArray(['type' => 'integer', 'nullable' => false, 'default' => '0']);
    }

    // Two separate error columns, both nullable.
    foreach (['last_transport_error', 'last_projection_error'] as $error) {
        expect($columns[$error])->toMatchArray(['type' => 'text', 'nullable' => true]);
    }

    // Lifecycle markers are nullable; the two required times are not.
    foreach (['last_published_at', 'projection_completed_at', 'exhausted_at'] as $marker) {
        expect($columns[$marker]['nullable'])->toBeTrue();
    }

    expect($columns['occurred_at']['nullable'])->toBeFalse()
        ->and($columns['available_at']['nullable'])->toBeFalse();

    $indexes = t5IndexCatalog('payment_outbox');

    expect(array_keys($indexes))->toEqualCanonicalizing([
        'payment_outbox_pkey',
        'payment_outbox_aggregate_version_unique',
        'payment_outbox_eligible_index',
    ]);

    // D13 read from the catalog, not inferred from the name: unique, on exactly
    // these two columns, IN THIS ORDER. A key that merely contains them, or
    // orders them the other way, is a different index with different lookup
    // behaviour even though the name would still match.
    expect($indexes['payment_outbox_aggregate_version_unique'])
        ->toMatchArray([
            'columns' => 'aggregate_uuid,aggregate_version',
            'unique' => true,
            'partial' => false,
        ]);
});

it('keeps every Payments-owned timestamp at microsecond precision with a time zone', function (): void {
    // ENUMERATED, not filtered. An earlier version looped over the columns that
    // were ALREADY `timestamp with time zone` and asserted precision on those,
    // so switching a column to `timestamp without time zone` removed it from the
    // loop and the test kept passing while asserting nothing about it.
    //
    // Laravel's Schema\Builder::$defaultTimePrecision is 0, so a bare
    // $table->timestamp() yields timestamp(0), which ROUNDS to whole seconds.
    $found = [];

    foreach (PAYMENTS_TABLES as $table) {
        foreach (t5Columns($table) as $name => $column) {
            if (str_starts_with($column['type'], 'timestamp')) {
                $found[] = "{$table}.{$name}";
            }
        }
    }

    // Catches a removed, renamed or newly added timestamp column in either
    // direction, so the per-column loop below can never silently shrink.
    expect($found)->toEqualCanonicalizing(T5_TIMESTAMP_COLUMNS);

    foreach (T5_TIMESTAMP_COLUMNS as $qualified) {
        [$table, $name] = explode('.', $qualified);

        expect(t5Columns($table)[$name])
            ->toMatchArray(['type' => 'timestamp with time zone', 'precision' => 6], $qualified);
    }
});

it('gives the raw idempotency key no dedicated column of its own', function (): void {
    // NARROW CLAIM, deliberately. This proves no column exists whose purpose is
    // the raw key; it cannot prove a raw key is never written into some other
    // column, and the exact column-set assertions above are what stop a new
    // column from appearing to hold one.
    //
    // T6 OWES THE REST: once the real writer exists it must prove the raw key
    // never enters a stored event, an outbox payload, or a log line. Hashing is
    // explicitly not encryption.
    $columns = DB::select(
        "select column_name from information_schema.columns
          where table_schema = current_schema()
            and table_name like 'payment%'
            and column_name like '%idempotency%'",
    );

    expect(collect($columns)->pluck('column_name')->all())->toBe(['idempotency_key_hash']);
});

it('keeps the receipt response body byte-exact rather than normalising it', function (): void {
    // The mutation this guards: jsonb reorders keys and strips the spacing, and
    // the replayed body stops being the bytes the first caller saw.
    $body = '{"status":"initiated",  "payment_id":"0197c9b0-2222-7000-9000-00000000bbbb"}';
    $id = insertReceipt(responseBody: $body);

    expect(DB::table('payment_idempotency_receipts')->where('id', $id)->value('response_body'))
        ->toBe($body);
});

it('arbitrates receipts by merchant and key hash, and by payment id globally', function (): void {
    // ASSERT THE CONSTRAINT NAME, NOT JUST THE EXCEPTION CLASS. Every receipt row
    // carries three unique keys (id, payment_id, merchant+hash). A test that only
    // checks for UniqueConstraintViolationException passes even when the key it
    // means to prove does not exist, because a different one fired first.
    $first = Merchant::factory()->create()->getKey();
    $second = Merchant::factory()->create()->getKey();
    $hash = str_repeat('a', 64);

    insertReceipt(merchantId: $first, keyHash: $hash);

    // Two merchants may reuse one key value; one merchant may not.
    insertReceipt(merchantId: $second, keyHash: $hash);

    expect(fn () => DB::transaction(fn () => insertReceipt(merchantId: $first, keyHash: $hash)))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_receipts_merchant_key_unique');

    $paymentId = (string) Str::uuid7();
    insertReceipt(merchantId: $first, paymentId: $paymentId);

    expect(fn () => DB::transaction(fn () => insertReceipt(merchantId: $second, paymentId: $paymentId)))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_receipts_payment_id_unique');

    expect(DB::table('payment_idempotency_receipts')->count())->toBe(3);
});

it('refuses to delete a merchant that owns a receipt', function (): void {
    // restrictOnDelete, not cascade: a financial record is never removed as a
    // side effect of deleting its owner.
    $merchant = Merchant::factory()->create();
    insertReceipt(merchantId: $merchant->getKey());

    expect(fn () => $merchant->delete())->toThrow(QueryException::class);
});

it('creates the eligibility index partial, on the documented columns, with both predicates required', function (): void {
    $indexes = t5IndexCatalog('payment_outbox');
    $eligible = $indexes['payment_outbox_eligible_index'] ?? null;

    expect($eligible)->not->toBeNull()
        ->and($eligible['columns'])->toBe('available_at,event_id')
        ->and($eligible['partial'])->toBeTrue();

    // AND, never OR. With OR the index would also carry rows that are completed
    // but not exhausted (and vice versa), so the relay's hot path would grow with
    // total history instead of with pending work. Parentheses and spacing are the
    // server's rendering and are normalised away before comparison.
    expect(t5NormalisePredicate($eligible['predicate']))
        ->toBe('projection_completed_at IS NULL AND exhausted_at IS NULL');

    // A full twin would defeat the partiality even while the partial index exists.
    $fullTwins = collect($indexes)->filter(
        fn (array $index): bool => $index['columns'] === 'available_at,event_id' && ! $index['partial'],
    );

    expect($fullTwins)->toBeEmpty();
});

it('refuses a second outbox envelope for one aggregate version', function (): void {
    // Distinct event ids on purpose: with the same id the PRIMARY KEY rejects the
    // row and the test passes whether or not D13's constraint exists.
    $aggregateUuid = (string) Str::uuid7();

    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: $aggregateUuid, aggregateVersion: 1);

    expect(fn () => DB::transaction(fn () => insertOutboxRow(
        eventId: (string) Str::uuid7(),
        aggregateUuid: $aggregateUuid,
        aggregateVersion: 1,
    )))->toThrow(UniqueConstraintViolationException::class, 'payment_outbox_aggregate_version_unique');

    // The same pair on a DIFFERENT version is fine — the constraint is not a
    // per-aggregate lock.
    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: $aggregateUuid, aggregateVersion: 2);

    // POSITIVE CONTROL. Version 1 is the version every payment's first event
    // carries, so a key accidentally written as unique on aggregate_version
    // alone would reject the second payment ever initiated. Two different
    // aggregates must both be able to store version 1.
    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: (string) Str::uuid7(), aggregateVersion: 1);
    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: (string) Str::uuid7(), aggregateVersion: 1);

    expect(DB::table('payment_outbox')->count())->toBe(4);
});

/*
|--------------------------------------------------------------------------
| T7 — the two transport counters T7 owns
|--------------------------------------------------------------------------
|
| T5 deliberately shipped no CHECK on any of the three counters, because no
| production writer existed and a constraint added then would have guarded
| columns nothing wrote. The decision splits along the same ownership line as
| the columns: T7 owns publish_count and consecutive_transport_failures; T8
| owns projection_failure_count.
|
| MEASURED FIRST, NOT ASSUMED. Laravel maps unsignedInteger to a plain PostgreSQL
| `integer` — the shape assertion above already reads `type => integer` from the
| catalog — so nothing rejected a negative counter before this migration. That is
| what makes the constraint load-bearing rather than decorative, and the
| measurement is the red run of the two tests below.
|
| T8's column is NOT asserted to stay unconstrained. A "globally unconstrained"
| assertion would mean T8 could not add its own constraint without deleting a
| test, which would make this file a veto over a decision it does not own.
|
*/

/**
 * Every CHECK constraint on a table, by name, with the server's own rendering of
 * its expression.
 *
 * Read from pg_constraint rather than from the migration source, for the reason
 * the file header already gives: re-reading the migration would only prove the
 * file says what it says.
 *
 * @return array<string, string>
 */
function t7CheckConstraints(string $table): array
{
    $rows = DB::select(
        <<<'SQL'
        select conname as name, pg_get_constraintdef(oid) as definition
          from pg_constraint
         where conrelid = to_regclass(?)::oid
           and contype = 'c'
         order by conname
        SQL,
        [$table],
    );

    $constraints = [];

    foreach ($rows as $row) {
        $constraints[(string) $row->name] = (string) $row->definition;
    }

    return $constraints;
}

it('constrains both transport counters T7 owns, under names PostgreSQL can still address', function (): void {
    $checks = t7CheckConstraints('payment_outbox');

    expect(array_keys($checks))->toContain(
        'payment_outbox_publish_count_non_negative',
        'payment_outbox_consecutive_transport_failures_non_negative',
    );

    // The expression, read from the catalog. A constraint with the right name and
    // the wrong predicate would otherwise pass.
    expect($checks['payment_outbox_publish_count_non_negative'])
        ->toContain('publish_count')
        ->toContain('>= 0');

    expect($checks['payment_outbox_consecutive_transport_failures_non_negative'])
        ->toContain('consecutive_transport_failures')
        ->toContain('>= 0');

    // PostgreSQL truncates identifiers at 63 bytes, and a truncated name cannot be
    // dropped by the name a later migration computes. Measured, not eyeballed.
    foreach (array_keys($checks) as $name) {
        expect(strlen($name))->toBeLessThan(64);
    }
});

it('refuses a negative publish_count and a negative consecutive_transport_failures', function (string $column): void {
    // DB::transaction() for the reason the file header gives: a failed statement
    // aborts the whole PostgreSQL transaction, and RefreshDatabase already has
    // this test inside one.
    $eventId = insertOutboxRow();

    expect(fn () => DB::transaction(fn () => DB::table('payment_outbox')
        ->where('event_id', $eventId)
        ->update([$column => -1])))
        ->toThrow(QueryException::class);

    // The row is untouched: the savepoint rolled back, not the whole test.
    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(0);
})->with(['publish_count', 'consecutive_transport_failures']);

it('reports 23514 naming the violated transport constraint', function (): void {
    // The SQLSTATE, read with the same classifier production uses, so a change to
    // that reader cannot silently stop this test from checking what it claims.
    $eventId = insertOutboxRow();

    try {
        DB::transaction(fn () => DB::table('payment_outbox')
            ->where('event_id', $eventId)
            ->update(['publish_count' => -1]));

        $sqlState = null;
        $message = '';
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
        $message = $exception->getMessage();
    }

    expect($sqlState)->toBe('23514')
        ->and($message)->toContain('payment_outbox_publish_count_non_negative');
});

/*
|--------------------------------------------------------------------------
| T8 — the durable projection surface
|--------------------------------------------------------------------------
|
| Two new tables and the third counter constraint, read from the catalogs for the
| reason the file header already gives.
|
| The two tables are asserted to carry EXACTLY the keys they document and nothing
| else. That is the assertion that keeps a speculative (merchant_id, payment_id),
| receipt event_id or standalone merchant_id index out of T8: each would need a
| measured query plan behind it, and the exact-set expectation is what forces that
| conversation instead of letting one appear quietly.
|
| payment_details has NO updated_at, and the shape assertion is what enforces it.
| At-least-once delivery means a duplicate must converge without rewriting
| business fields; with no mutable column there is nothing legitimate for a
| duplicate to touch, so the absence is a durable contract rather than an
| oversight.
|
*/

/**
 * The PRIMARY KEY CONSTRAINT, from pg_constraint rather than from pg_index.
 *
 * t5IndexCatalog() sees the backing index, which is enough to know the columns
 * are unique and in which order, but NOT enough to know a primary key exists:
 * a plain unique index over the same NOT NULL columns looks identical there.
 * Only contype = 'p' distinguishes them.
 *
 * WHY THAT DISTINCTION IS WORTH ASSERTING. Not because PostgreSQL requires a
 * primary key for the operations T8 performs — it does not. `ON CONFLICT
 * (columns) DO NOTHING` infers any matching unique index, and a foreign key may
 * reference any appropriate unique key, so a unique-index-only variant of either
 * table would still function. The assertion exists because H2 approved a specific
 * schema, and `(projector_name, event_id)` and `payment_id` are PRIMARY KEYS in
 * it. This is a schema-contract check, not a functional prerequisite.
 *
 * Columns are ordered by conkey position, which is the key's own order.
 *
 * @return array{name: string, columns: string}|null
 */
function t8PrimaryKey(string $table): ?array
{
    $rows = DB::select(
        <<<'SQL'
        select c.conname as name,
               (select string_agg(a.attname, ',' order by k.ord)
                  from unnest(c.conkey) with ordinality as k(attnum, ord)
                  join pg_attribute a
                    on a.attrelid = c.conrelid and a.attnum = k.attnum) as columns
          from pg_constraint c
         where c.conrelid = to_regclass(?)::oid
           and c.contype = 'p'
        SQL,
        [$table],
    );

    if ($rows === []) {
        return null;
    }

    return ['name' => (string) $rows[0]->name, 'columns' => (string) $rows[0]->columns];
}

/**
 * Foreign keys by name, with the LOCAL columns, the referenced table AND its
 * columns, and the ON DELETE action read as the catalog's single-letter code:
 * 'r' restrict, 'c' cascade, 'a' no action, 'n' set null, 'd' set default.
 *
 * All four parts matter, and none of them is visible in the constraint name.
 * A key pointing at the wrong local column, at the wrong referenced column, or
 * carrying ON DELETE CASCADE would remove a financial record as a side effect of
 * deleting a merchant, under a name that still reads correctly.
 *
 * @return array<string, array{columns: string, references: string, on_delete: string}>
 */
function t8ForeignKeys(string $table): array
{
    $rows = DB::select(
        <<<'SQL'
        select c.conname as name,
               (select string_agg(a.attname, ',' order by k.ord)
                  from unnest(c.conkey) with ordinality as k(attnum, ord)
                  join pg_attribute a
                    on a.attrelid = c.conrelid and a.attnum = k.attnum) as columns,
               r.relname as referenced_table,
               (select string_agg(a.attname, ',' order by k.ord)
                  from unnest(c.confkey) with ordinality as k(attnum, ord)
                  join pg_attribute a
                    on a.attrelid = c.confrelid and a.attnum = k.attnum) as referenced_columns,
               c.confdeltype as on_delete
          from pg_constraint c
          join pg_class r on r.oid = c.confrelid
         where c.conrelid = to_regclass(?)::oid
           and c.contype = 'f'
         order by c.conname
        SQL,
        [$table],
    );

    return collect($rows)->keyBy('name')->map(fn (object $r): array => [
        'columns' => (string) $r->columns,
        'references' => "{$r->referenced_table}.{$r->referenced_columns}",
        'on_delete' => (string) $r->on_delete,
    ])->all();
}

/** One projector receipt. Fresh event id unless overridden. */
function insertProjectorReceipt(
    string $projectorName = 'payment_details',
    ?string $eventId = null,
): string {
    $eventId ??= (string) Str::uuid7();

    DB::table('payment_projector_receipts')->insert([
        'projector_name' => $projectorName,
        'event_id' => $eventId,
        'created_at' => now(),
    ]);

    return $eventId;
}

/** One projected payment row. Fresh payment id and source event id unless overridden. */
function insertPaymentDetails(
    ?int $merchantId = null,
    ?string $paymentId = null,
    ?string $sourceEventId = null,
): string {
    $paymentId ??= (string) Str::uuid7();

    DB::table('payment_details')->insert([
        'payment_id' => $paymentId,
        'source_event_id' => $sourceEventId ?? (string) Str::uuid7(),
        'merchant_id' => $merchantId ?? Merchant::factory()->create()->getKey(),
        'amount_minor' => 1_250,
        'currency' => 'EUR',
        'status' => 'initiated',
        'aggregate_version' => 1,
        'initiated_at' => now(),
    ]);

    return $paymentId;
}

it('gives payment_projector_receipts its exact documented shape and composite key', function (): void {
    $columns = t5Columns('payment_projector_receipts');

    expect(array_keys($columns))->toEqualCanonicalizing(['projector_name', 'event_id', 'created_at'])
        ->and($columns['projector_name'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 255])
        ->and($columns['event_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        // NOT NULL explicitly: the receipt's whole meaning is "this projector
        // consumed this event AT this time", and a nullable created_at would let
        // a row exist that cannot say when.
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false]);

    $indexes = t5IndexCatalog('payment_projector_receipts');

    // The primary key is the ONLY index. Nothing here reads by event_id alone in
    // T8, and an index for a query that does not exist is dead weight.
    expect(array_keys($indexes))->toEqualCanonicalizing(['payment_projector_receipts_pkey']);

    // Columns IN THIS ORDER, read from the catalog rather than inferred from the
    // name: projector_name leads, because it is what a second projector over the
    // same events would differ on.
    expect($indexes['payment_projector_receipts_pkey'])
        ->toMatchArray([
            'columns' => 'projector_name,event_id',
            'unique' => true,
            'partial' => false,
        ]);

    // The approved H2 shape says PRIMARY KEY, so that is what is asserted, read
    // from pg_constraint because the index assertion above cannot tell a primary
    // key from a plain unique index over the same NOT NULL columns. The
    // consumer's ON CONFLICT (projector_name, event_id) DO NOTHING would infer
    // either one; the contract, not the inference, is what this pins.
    expect(t8PrimaryKey('payment_projector_receipts'))->toBe([
        'name' => 'payment_projector_receipts_pkey',
        'columns' => 'projector_name,event_id',
    ]);

    // NO foreign key, in either direction. payment_outbox is rebuildable delivery
    // state and stored_events is kept identical to the vendor stub; the receipt
    // has to outlive both, and a key to either would make every future
    // reconciliation a schema negotiation.
    expect(t8ForeignKeys('payment_projector_receipts'))->toBeEmpty();
});

it('arbitrates projector receipts by projector name and event id together', function (): void {
    // The composite key IS the idempotency mechanism: the consumer inserts with
    // ON CONFLICT DO NOTHING and reads the conflict as "already projected". If the
    // key were on event_id alone, a second projector could never consume the same
    // event; if it were on projector_name alone, the first event would lock the
    // projector out of every later one.
    $eventId = (string) Str::uuid7();

    insertProjectorReceipt(eventId: $eventId);

    expect(fn () => DB::transaction(fn () => insertProjectorReceipt(eventId: $eventId)))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_projector_receipts_pkey');

    // POSITIVE CONTROLS, both directions of the key.
    insertProjectorReceipt(projectorName: 'payment_audit', eventId: $eventId);
    insertProjectorReceipt();

    expect(DB::table('payment_projector_receipts')->count())->toBe(3);
});

it('gives payment_details its exact documented shape with no mutable column', function (): void {
    $columns = t5Columns('payment_details');

    // The exact set is the contract. An added updated_at would give a duplicate
    // delivery something legitimate to rewrite, which is precisely what the
    // at-least-once convergence rule forbids.
    expect(array_keys($columns))->toEqualCanonicalizing([
        'payment_id', 'source_event_id', 'merchant_id', 'amount_minor',
        'currency', 'status', 'aggregate_version', 'initiated_at',
    ])
        ->and($columns['payment_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['source_event_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['merchant_id'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        // Minor units in an integer column: money never touches a float here.
        ->and($columns['amount_minor'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        // char(3), not varchar: an ISO 4217 alphabetic code is always exactly 3.
        ->and($columns['currency'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 3])
        ->and($columns['status'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 32])
        ->and($columns['aggregate_version'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        // NOT NULL explicitly. initiated_at is derived from the event's
        // occurred_at, which is itself NOT NULL on payment_outbox, so a nullable
        // column here would only ever record that the derivation was skipped.
        ->and($columns['initiated_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false]);

    // No column default anywhere: every value is derived from the source event, so
    // a missing one must fail rather than quietly take a fallback.
    foreach ($columns as $name => $column) {
        expect($column['default'])->toBeNull($name);
    }

    $indexes = t5IndexCatalog('payment_details');

    // Exactly two keys, and no third. The point lookups T8 performs are served by
    // both; a (merchant_id, payment_id) or standalone merchant_id index needs
    // measured query-plan evidence T8 does not have.
    expect(array_keys($indexes))->toEqualCanonicalizing([
        'payment_details_pkey',
        'payment_details_source_event_id_unique',
    ])
        ->and($indexes['payment_details_pkey'])
        ->toMatchArray(['columns' => 'payment_id', 'unique' => true, 'partial' => false])
        ->and($indexes['payment_details_source_event_id_unique'])
        ->toMatchArray(['columns' => 'source_event_id', 'unique' => true, 'partial' => false]);

    // PRIMARY KEY on payment_id, per the approved H2 shape, rather than a unique
    // index that would read the same in the catalog above.
    expect(t8PrimaryKey('payment_details'))->toBe([
        'name' => 'payment_details_pkey',
        'columns' => 'payment_id',
    ]);

    // EXACTLY ONE foreign key, asserted as a whole array rather than by
    // toHaveKey: a second key added later — to payment_outbox or stored_events,
    // both of which this table must outlive — has to fail here rather than pass
    // unnoticed beside the one being checked. Local column, referenced
    // merchants.id, and RESTRICT are all read from the catalog.
    expect(t8ForeignKeys('payment_details'))->toBe([
        'payment_details_merchant_id_foreign' => [
            'columns' => 'merchant_id',
            'references' => 'merchants.id',
            'on_delete' => 'r',
        ],
    ]);
});

it('refuses a contradictory payment id or source event id rather than upserting it', function (): void {
    // BOTH keys asserted BY NAME. A row carries two, so a test that only checked
    // for UniqueConstraintViolationException would pass whether or not the key it
    // means to prove exists. Contradictory history must fail visibly: the consumer
    // never upserts this table, because an upsert would let a second, disagreeing
    // event silently overwrite a projected payment.
    $merchant = Merchant::factory()->create()->getKey();
    $paymentId = (string) Str::uuid7();
    $sourceEventId = (string) Str::uuid7();

    insertPaymentDetails(merchantId: $merchant, paymentId: $paymentId, sourceEventId: $sourceEventId);

    expect(fn () => DB::transaction(fn () => insertPaymentDetails(
        merchantId: $merchant,
        paymentId: $paymentId,
    )))->toThrow(UniqueConstraintViolationException::class, 'payment_details_pkey');

    expect(fn () => DB::transaction(fn () => insertPaymentDetails(
        merchantId: $merchant,
        sourceEventId: $sourceEventId,
    )))->toThrow(UniqueConstraintViolationException::class, 'payment_details_source_event_id_unique');

    // POSITIVE CONTROL: a different payment for the same merchant is the normal
    // case and must pass, so neither key is accidentally merchant-wide.
    insertPaymentDetails(merchantId: $merchant);

    expect(DB::table('payment_details')->count())->toBe(2);
});

it('refuses to delete a merchant that owns a projected payment', function (): void {
    // The behavioural half of the on_delete assertion above: restrictOnDelete, not
    // cascade, for the same reason payment_idempotency_receipts uses it.
    $merchant = Merchant::factory()->create();
    insertPaymentDetails(merchantId: $merchant->getKey());

    expect(fn () => $merchant->delete())->toThrow(QueryException::class);
});

it('constrains the projection counter and the generation fence T8 owns, under addressable names', function (): void {
    $checks = t7CheckConstraints('payment_outbox');

    expect(array_keys($checks))->toContain(
        'payment_outbox_projection_failure_count_non_negative',
        'payment_outbox_projection_failure_generation_non_negative',
    );

    // THE EXACT PREDICATE, not two toContain() probes. A constraint reading
    // `check (projection_failure_count >= 0 and projection_failure_count <= 5)`
    // contains both fragments and would have satisfied the looser assertion,
    // while silently making the fifth confirmed failure unwritable. Parentheses
    // and spacing are the server's rendering and are normalised away, exactly as
    // the eligibility-index predicate assertion does.
    expect(t5NormalisePredicate($checks['payment_outbox_projection_failure_count_non_negative']))
        ->toBe('CHECK projection_failure_count >= 0');

    expect(t5NormalisePredicate($checks['payment_outbox_projection_failure_generation_non_negative']))
        ->toBe('CHECK projection_failure_generation >= 0');

    // 63-byte identifier limit, measured rather than eyeballed: the longer of the
    // two names is 57 bytes, and a truncated name cannot be dropped by the name
    // down() computes.
    foreach (array_keys($checks) as $name) {
        expect(strlen($name))->toBeLessThan(64);
    }
});

it('refuses a negative projection_failure_count and a negative projection_failure_generation', function (string $column): void {
    // Laravel maps unsignedInteger to a plain PostgreSQL `integer` — the shape
    // assertion above reads `type => integer` from the catalog — so this CHECK is
    // the only thing rejecting a negative from an operator UPDATE or a later
    // repair command. Red first: without the constraint the update succeeds.
    $eventId = insertOutboxRow();

    expect(fn () => DB::transaction(fn () => DB::table('payment_outbox')
        ->where('event_id', $eventId)
        ->update([$column => -1])))
        ->toThrow(QueryException::class);

    // The row is untouched: the savepoint rolled back, not the whole test.
    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(0);

    // POSITIVE CONTROL, and the half that makes the refusal above mean something.
    // Without it a constraint of `check (column = 0)` would still reject -1 and
    // pass this test, while making every legitimate write impossible: the failure
    // counter must reach five for exhaustion, and the generation must climb past
    // zero on the first operator retry.
    DB::table('payment_outbox')->where('event_id', $eventId)->update([$column => 5]);

    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(5);
})->with(['projection_failure_count', 'projection_failure_generation']);

it('lets the projection counter and the generation fence advance independently', function (): void {
    // The two columns are written by DIFFERENT owners — the failure recorder
    // increments the count, operator retry resets it and increments the
    // generation — so a constraint or default coupling them would break the reset
    // cycle. Walks a full cycle: four failures, exhaustion at five, then the
    // retry that zeroes the counter and advances the fence past zero.
    $eventId = insertOutboxRow();
    $row = fn (): object => (object) (array) DB::table('payment_outbox')->where('event_id', $eventId)->first();

    for ($count = 1; $count <= 5; $count++) {
        DB::table('payment_outbox')->where('event_id', $eventId)->update(['projection_failure_count' => $count]);
    }

    expect((int) $row()->projection_failure_count)->toBe(5)
        ->and((int) $row()->projection_failure_generation)->toBe(0);

    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'projection_failure_count' => 0,
        'projection_failure_generation' => DB::raw('projection_failure_generation + 1'),
    ]);

    expect((int) $row()->projection_failure_count)->toBe(0)
        ->and((int) $row()->projection_failure_generation)->toBe(1);

    // MONOTONIC IN PRACTICE, not merely non-negative: a second retry cycle must
    // produce a fence value the first cycle's late recorder cannot match.
    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'projection_failure_generation' => DB::raw('projection_failure_generation + 1'),
    ]);

    expect((int) $row()->projection_failure_generation)->toBe(2);
});

it('reports 23514 naming the violated projection constraint', function (): void {
    // The SQLSTATE, read with the same classifier production uses, because T8's
    // failure classification is required to key on SQLSTATE and owned constraint
    // identity rather than on exception-message matching.
    $eventId = insertOutboxRow();

    try {
        DB::transaction(fn () => DB::table('payment_outbox')
            ->where('event_id', $eventId)
            ->update(['projection_failure_count' => -1]));

        $sqlState = null;
        $message = '';
    } catch (QueryException $exception) {
        $sqlState = RetryingAtomicTransaction::sqlStateOf($exception);
        $message = $exception->getMessage();
    }

    expect($sqlState)->toBe('23514')
        ->and($message)->toContain('payment_outbox_projection_failure_count_non_negative');
});
