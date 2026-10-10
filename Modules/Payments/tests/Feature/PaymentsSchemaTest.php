<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Infrastructure\Persistence\RetryingAtomicTransaction;
use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;

// Expected violations run inside DB::transaction(): the savepoint keeps RefreshDatabase's transaction usable after the failure.
const PAYMENTS_TABLES = [
    'payment_idempotency_receipts',
    'payment_outbox',
    'payment_projector_receipts',
    'payment_details',
    'payment_projection_controls',
];

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
    'payment_projection_controls.cleared_at',
    'payment_projection_controls.created_at',
    'payment_projection_controls.started_at',
    'payment_projection_controls.updated_at',
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

function t5NormalisePredicate(?string $predicate): string
{
    return trim((string) preg_replace('/\s+/', ' ', str_replace(['(', ')'], '', (string) $predicate)));
}

function insertReceipt(
    ?int $merchantId = null,
    ?string $keyHash = null,
    ?string $paymentId = null,
    string $responseBody = '{"status":"initiated"}',
    ?string $intentId = null,
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
        'intent_id' => $intentId,
    ]);

    return $id;
}

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

it('refuses a second stored event at the same aggregate version even under another event class', function (): void {
    // An insert, not the catalog: a key widened with event_class would keep the same name.
    $aggregateUuid = (string) Str::uuid7();

    insertStoredEvent($aggregateUuid, 1);

    expect(fn () => DB::transaction(fn () => insertStoredEvent($aggregateUuid, 1, 'payments.payment_authorized')))
        ->toThrow(UniqueConstraintViolationException::class, 'stored_events_aggregate_uuid_aggregate_version_unique');

    insertStoredEvent($aggregateUuid, 2);

    expect(DB::table('stored_events')->where('aggregate_uuid', $aggregateUuid)->count())->toBe(2);
});

it('gives payment_idempotency_receipts its exact documented shape', function (): void {
    $columns = t5Columns('payment_idempotency_receipts');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'merchant_id', 'idempotency_key_hash', 'request_fingerprint',
        'payment_id', 'response_status', 'response_body', 'response_location',
        'created_at', 'updated_at', 'intent_id',
    ])
        ->and($columns['id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['intent_id'])->toMatchArray(['type' => 'uuid', 'nullable' => true, 'default' => null])
        ->and($columns['merchant_id'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['idempotency_key_hash'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 64])
        ->and($columns['request_fingerprint'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 64])
        ->and($columns['payment_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['response_status'])->toMatchArray(['type' => 'smallint', 'nullable' => false])
        ->and($columns['response_body'])->toMatchArray(['type' => 'text', 'nullable' => false])
        ->and($columns['response_location'])->toMatchArray(['type' => 'text', 'nullable' => false]);

    $indexes = t5IndexCatalog('payment_idempotency_receipts');

    expect(array_keys($indexes))->toEqualCanonicalizing([
        'payment_idempotency_receipts_pkey',
        'payment_receipts_merchant_key_unique',
        'payment_receipts_payment_id_unique',
        'payment_receipts_merchant_payment_index',
        'payment_receipts_merchant_intent_unique',
        'payment_receipts_merchant_recent_intent_index',
    ])
        ->and($indexes['payment_receipts_merchant_key_unique'])
        ->toMatchArray(['columns' => 'merchant_id,idempotency_key_hash', 'unique' => true])
        ->and($indexes['payment_receipts_payment_id_unique'])
        ->toMatchArray(['columns' => 'payment_id', 'unique' => true])
        ->and($indexes['payment_receipts_merchant_payment_index'])
        ->toMatchArray(['columns' => 'merchant_id,payment_id', 'unique' => false])
        ->and($indexes['payment_receipts_merchant_intent_unique'])
        ->toMatchArray(['columns' => 'merchant_id,intent_id', 'unique' => true, 'partial' => false])
        ->and($indexes['payment_receipts_merchant_recent_intent_index'])
        ->toMatchArray(['columns' => 'merchant_id,created_at,id', 'unique' => false, 'partial' => true]);
});

it('orders the recent-intent index newest first and covers only intent-bearing receipts', function (): void {
    $definition = (string) DB::selectOne(
        "select indexdef from pg_indexes
          where schemaname = current_schema() and indexname = 'payment_receipts_merchant_recent_intent_index'",
    )?->indexdef;

    expect($definition)->toContain('(merchant_id, created_at DESC, id DESC)')
        ->and(t5NormalisePredicate(t5IndexCatalog('payment_idempotency_receipts')['payment_receipts_merchant_recent_intent_index']['predicate']))
        ->toBe('intent_id IS NOT NULL');
});

it('arbitrates intents per merchant, never globally, and leaves historical receipts without one', function (): void {
    $first = Merchant::factory()->create()->getKey();
    $second = Merchant::factory()->create()->getKey();
    $intent = (string) Str::uuid();

    insertReceipt(merchantId: $first, intentId: $intent);
    insertReceipt(merchantId: $second, intentId: $intent);

    // NULLs are distinct in a unique index, so any number of historical receipts may lack an intent.
    insertReceipt(merchantId: $first);
    insertReceipt(merchantId: $first);

    expect(fn () => DB::transaction(fn () => insertReceipt(merchantId: $first, intentId: $intent)))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_receipts_merchant_intent_unique');

    expect(DB::table('payment_idempotency_receipts')->count())->toBe(4)
        ->and(DB::table('payment_idempotency_receipts')->whereNull('intent_id')->count())->toBe(2);
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

    foreach ([
        'publish_count',
        'consecutive_transport_failures',
        'projection_failure_count',
        'projection_failure_generation',
    ] as $counter) {
        expect($columns[$counter])->toMatchArray(['type' => 'integer', 'nullable' => false, 'default' => '0']);
    }

    foreach (['last_transport_error', 'last_projection_error'] as $error) {
        expect($columns[$error])->toMatchArray(['type' => 'text', 'nullable' => true]);
    }

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

    expect($indexes['payment_outbox_aggregate_version_unique'])
        ->toMatchArray([
            'columns' => 'aggregate_uuid,aggregate_version',
            'unique' => true,
            'partial' => false,
        ]);
});

it('keeps every Payments-owned timestamp at microsecond precision with a time zone', function (): void {
    // Enumerated, not filtered by type, so a column changed to timestamp without time zone still fails.
    $found = [];

    foreach (PAYMENTS_TABLES as $table) {
        foreach (t5Columns($table) as $name => $column) {
            if (str_starts_with($column['type'], 'timestamp')) {
                $found[] = "{$table}.{$name}";
            }
        }
    }

    expect($found)->toEqualCanonicalizing(T5_TIMESTAMP_COLUMNS);

    foreach (T5_TIMESTAMP_COLUMNS as $qualified) {
        [$table, $name] = explode('.', $qualified);

        expect(t5Columns($table)[$name])
            ->toMatchArray(['type' => 'timestamp with time zone', 'precision' => 6], $qualified);
    }
});

it('gives the raw idempotency key no dedicated column of its own', function (): void {
    $columns = DB::select(
        "select column_name from information_schema.columns
          where table_schema = current_schema()
            and table_name like 'payment%'
            and column_name like '%idempotency%'",
    );

    expect(collect($columns)->pluck('column_name')->all())->toBe(['idempotency_key_hash']);
});

it('keeps the receipt response body byte-exact rather than normalising it', function (): void {
    $body = '{"status":"initiated",  "payment_id":"0197c9b0-2222-7000-9000-00000000bbbb"}';
    $id = insertReceipt(responseBody: $body);

    expect(DB::table('payment_idempotency_receipts')->where('id', $id)->value('response_body'))
        ->toBe($body);
});

it('arbitrates receipts by merchant and key hash, and by payment id globally', function (): void {
    // Constraint names, not only the exception class: each receipt has three unique keys.
    $first = Merchant::factory()->create()->getKey();
    $second = Merchant::factory()->create()->getKey();
    $hash = str_repeat('a', 64);

    insertReceipt(merchantId: $first, keyHash: $hash);

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

    expect(t5NormalisePredicate($eligible['predicate']))
        ->toBe('projection_completed_at IS NULL AND exhausted_at IS NULL');

    $fullTwins = collect($indexes)->filter(
        fn (array $index): bool => $index['columns'] === 'available_at,event_id' && ! $index['partial'],
    );

    expect($fullTwins)->toBeEmpty();
});

it('refuses a second outbox envelope for one aggregate version', function (): void {
    $aggregateUuid = (string) Str::uuid7();

    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: $aggregateUuid, aggregateVersion: 1);

    expect(fn () => DB::transaction(fn () => insertOutboxRow(
        eventId: (string) Str::uuid7(),
        aggregateUuid: $aggregateUuid,
        aggregateVersion: 1,
    )))->toThrow(UniqueConstraintViolationException::class, 'payment_outbox_aggregate_version_unique');

    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: $aggregateUuid, aggregateVersion: 2);

    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: (string) Str::uuid7(), aggregateVersion: 1);
    insertOutboxRow(eventId: (string) Str::uuid7(), aggregateUuid: (string) Str::uuid7(), aggregateVersion: 1);

    expect(DB::table('payment_outbox')->count())->toBe(4);
});

/** @return array<string, string> */
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

    expect($checks['payment_outbox_publish_count_non_negative'])
        ->toContain('publish_count')
        ->toContain('>= 0');

    expect($checks['payment_outbox_consecutive_transport_failures_non_negative'])
        ->toContain('consecutive_transport_failures')
        ->toContain('>= 0');

    // PostgreSQL truncates identifiers at 63 bytes.
    foreach (array_keys($checks) as $name) {
        expect(strlen($name))->toBeLessThan(64);
    }
});

it('refuses a negative publish_count and a negative consecutive_transport_failures', function (string $column): void {
    $eventId = insertOutboxRow();

    expect(fn () => DB::transaction(fn () => DB::table('payment_outbox')
        ->where('event_id', $eventId)
        ->update([$column => -1])))
        ->toThrow(QueryException::class);

    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(0);
})->with(['publish_count', 'consecutive_transport_failures']);

it('reports 23514 naming the violated transport constraint', function (): void {
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

/**
 * From pg_constraint: in pg_index a unique index over NOT NULL columns looks identical to a primary key.
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
 * on_delete is the catalog code: 'r' restrict, 'c' cascade, 'a' no action, 'n' set null, 'd' set default.
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
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false]);

    $indexes = t5IndexCatalog('payment_projector_receipts');

    expect(array_keys($indexes))->toEqualCanonicalizing(['payment_projector_receipts_pkey']);

    expect($indexes['payment_projector_receipts_pkey'])
        ->toMatchArray([
            'columns' => 'projector_name,event_id',
            'unique' => true,
            'partial' => false,
        ]);

    expect(t8PrimaryKey('payment_projector_receipts'))->toBe([
        'name' => 'payment_projector_receipts_pkey',
        'columns' => 'projector_name,event_id',
    ]);

    expect(t8ForeignKeys('payment_projector_receipts'))->toBeEmpty();
});

it('arbitrates projector receipts by projector name and event id together', function (): void {
    $eventId = (string) Str::uuid7();

    insertProjectorReceipt(eventId: $eventId);

    expect(fn () => DB::transaction(fn () => insertProjectorReceipt(eventId: $eventId)))
        ->toThrow(UniqueConstraintViolationException::class, 'payment_projector_receipts_pkey');

    insertProjectorReceipt(projectorName: 'payment_audit', eventId: $eventId);
    insertProjectorReceipt();

    expect(DB::table('payment_projector_receipts')->count())->toBe(3);
});

it('gives payment_details its exact documented shape with no mutable column', function (): void {
    $columns = t5Columns('payment_details');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'payment_id', 'source_event_id', 'merchant_id', 'amount_minor',
        'currency', 'status', 'aggregate_version', 'initiated_at',
    ])
        ->and($columns['payment_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['source_event_id'])->toMatchArray(['type' => 'uuid', 'nullable' => false])
        ->and($columns['merchant_id'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['amount_minor'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['currency'])->toMatchArray(['type' => 'character', 'nullable' => false, 'length' => 3])
        ->and($columns['status'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 32])
        ->and($columns['aggregate_version'])->toMatchArray(['type' => 'bigint', 'nullable' => false])
        ->and($columns['initiated_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false]);

    foreach ($columns as $name => $column) {
        expect($column['default'])->toBeNull($name);
    }

    $indexes = t5IndexCatalog('payment_details');

    expect(array_keys($indexes))->toEqualCanonicalizing([
        'payment_details_pkey',
        'payment_details_source_event_id_unique',
    ])
        ->and($indexes['payment_details_pkey'])
        ->toMatchArray(['columns' => 'payment_id', 'unique' => true, 'partial' => false])
        ->and($indexes['payment_details_source_event_id_unique'])
        ->toMatchArray(['columns' => 'source_event_id', 'unique' => true, 'partial' => false]);

    expect(t8PrimaryKey('payment_details'))->toBe([
        'name' => 'payment_details_pkey',
        'columns' => 'payment_id',
    ]);

    expect(t8ForeignKeys('payment_details'))->toBe([
        'payment_details_merchant_id_foreign' => [
            'columns' => 'merchant_id',
            'references' => 'merchants.id',
            'on_delete' => 'r',
        ],
    ]);
});

it('refuses a contradictory payment id or source event id rather than upserting it', function (): void {
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

    insertPaymentDetails(merchantId: $merchant);

    expect(DB::table('payment_details')->count())->toBe(2);
});

it('refuses to delete a merchant that owns a projected payment', function (): void {
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

    // The exact predicate: an added upper bound would still satisfy toContain() probes.
    expect(t5NormalisePredicate($checks['payment_outbox_projection_failure_count_non_negative']))
        ->toBe('CHECK projection_failure_count >= 0');

    expect(t5NormalisePredicate($checks['payment_outbox_projection_failure_generation_non_negative']))
        ->toBe('CHECK projection_failure_generation >= 0');

    foreach (array_keys($checks) as $name) {
        expect(strlen($name))->toBeLessThan(64);
    }
});

it('refuses a negative projection_failure_count and a negative projection_failure_generation', function (string $column): void {
    $eventId = insertOutboxRow();

    expect(fn () => DB::transaction(fn () => DB::table('payment_outbox')
        ->where('event_id', $eventId)
        ->update([$column => -1])))
        ->toThrow(QueryException::class);

    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(0);

    // A `column = 0` check would also reject -1, so a legitimate positive write must still succeed.
    DB::table('payment_outbox')->where('event_id', $eventId)->update([$column => 5]);

    expect((int) DB::table('payment_outbox')->where('event_id', $eventId)->value($column))->toBe(5);
})->with(['projection_failure_count', 'projection_failure_generation']);

it('lets the projection counter and the generation fence advance independently', function (): void {
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

    DB::table('payment_outbox')->where('event_id', $eventId)->update([
        'projection_failure_generation' => DB::raw('projection_failure_generation + 1'),
    ]);

    expect((int) $row()->projection_failure_generation)->toBe(2);
});

it('reports 23514 naming the violated projection constraint', function (): void {
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

it('gives payment_projection_controls its exact documented shape', function (): void {
    $columns = t5Columns('payment_projection_controls');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'projection', 'state', 'owner_token', 'owner_pid',
        'started_at', 'cleared_at', 'created_at', 'updated_at',
    ])
        ->and($columns['projection'])->toMatchArray(['type' => 'character varying', 'nullable' => false])
        ->and($columns['state'])->toMatchArray(['type' => 'character varying', 'nullable' => false, 'length' => 32])
        ->and($columns['owner_token'])->toMatchArray(['type' => 'uuid', 'nullable' => true])
        ->and($columns['owner_pid'])->toMatchArray(['type' => 'integer', 'nullable' => true])
        ->and($columns['started_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => true])
        ->and($columns['cleared_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => true])
        ->and($columns['created_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false])
        ->and($columns['updated_at'])->toMatchArray(['type' => 'timestamp with time zone', 'nullable' => false]);

    foreach ($columns as $name => $column) {
        expect($column['default'])->toBeNull($name);
    }

    $indexes = t5IndexCatalog('payment_projection_controls');

    expect(array_keys($indexes))->toEqualCanonicalizing(['payment_projection_controls_pkey'])
        ->and($indexes['payment_projection_controls_pkey'])
        ->toMatchArray(['columns' => 'projection', 'unique' => true, 'partial' => false]);

    expect(t8PrimaryKey('payment_projection_controls'))->toBe([
        'name' => 'payment_projection_controls_pkey',
        'columns' => 'projection',
    ]);

    expect(t8ForeignKeys('payment_projection_controls'))->toBe([]);
});

it('seeds exactly one ready control row as part of the schema', function (): void {
    $rows = DB::table('payment_projection_controls')->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->projection)->toBe('payment_details');
    expect($rows[0]->state)->toBe('ready');
    expect($rows[0]->owner_token)->toBeNull();
    expect($rows[0]->owner_pid)->toBeNull();
    expect($rows[0]->started_at)->toBeNull();
    expect($rows[0]->cleared_at)->toBeNull();

    expect($rows[0]->projection)->toBe(PaymentProjectionConsumer::PROJECTOR_NAME);
});

it('constrains the projection gate state machine under addressable names', function (): void {
    $checks = t7CheckConstraints('payment_projection_controls');

    expect(array_keys($checks))->toEqualCanonicalizing([
        'payment_projection_controls_state_allowed',
        'payment_projection_controls_ready_unowned',
        'payment_projection_controls_rebuilding_owned',
    ]);

    foreach (array_keys($checks) as $name) {
        expect(strlen($name))->toBeLessThan(64);
    }
});
