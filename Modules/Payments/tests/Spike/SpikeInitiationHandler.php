<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The write closure under test, shaped like the spec's atomic write without
 * implementing any of it for production.
 *
 * Step order is load-bearing. Object construction sits BEFORE the contention probe
 * so that a failing attempt has still built its aggregate and event, which is what
 * makes Task 4's fresh-object comparison meaningful rather than vacuous: an attempt
 * that failed before constructing anything would contribute no identity to compare.
 *
 * Callers wrap this in DB::connection(SpikeDatabase::CONNECTION)->transaction($c, 1)
 * — one attempt, because the spec puts the retry budget in the outer loop and
 * Laravel rolls back and rethrows on any failure at that depth.
 */
final readonly class SpikeInitiationHandler
{
    public function __construct(
        private SpikeProbeLog $log,
        private ?Closure $contentionProbe = null,
    ) {}

    public function __invoke(SpikeCommandContext $context): void
    {
        $connection = DB::connection(SpikeDatabase::CONNECTION);

        // 1. Reserve the idempotency receipt, and REQUIRE that it reserved.
        //
        //    insertOrIgnore() returning 0 is meaningful in production, where it
        //    means a concurrent request won the key. In the spike there is no
        //    concurrency and every context carries a unique hash, so 0 can only
        //    mean a row survived from an earlier test. Accepting it silently is
        //    what turns a broken teardown into a passing write.
        $reserved = $connection->table('spike_payment_receipts')->insertOrIgnore([
            'merchant_id' => $context->merchantId,
            'idempotency_key_hash' => $context->keyHash,
        ]);

        if ($reserved !== 1) {
            throw new RuntimeException(sprintf(
                'Receipt reservation inserted %d rows for merchant %d and key %s. '
                .'Every spike context carries a unique key hash and nothing here runs '
                .'concurrently, so this means stale rows survived a previous test.',
                $reserved,
                $context->merchantId,
                $context->keyHash,
            ));
        }

        $this->log->record('receipt', SpikeDatabase::probe());

        // 2. Build FRESH mutable objects from the immutable context. retrieve() is
        //    static and resolves through app(), so every attempt gets a new
        //    aggregate; loadUuid() would reuse the receiver and defeat that.
        $aggregate = SpikePaymentAggregate::retrieve($context->paymentId);
        $event = new SpikePaymentInitiated(
            eventId: $context->eventId,
            paymentId: $context->paymentId,
            merchantId: $context->merchantId,
            amountMinor: $context->amountMinor,
            currency: $context->currency,
            occurredAt: $context->occurredAt,
        );
        $this->log->recordBuild($aggregate, $event, $context);
        $this->log->record('objects-built', SpikeDatabase::probe());

        // 3. Optional real-PostgreSQL contention, supplied by Task 4 only.
        if ($this->contentionProbe instanceof Closure) {
            ($this->contentionProbe)();
        }

        // 4. Persist, which runs the synchronous outbox writer inline.
        $aggregate->recordThat($event)->persist();
        $this->log->record('after-persist', SpikeDatabase::probe());
    }
}
