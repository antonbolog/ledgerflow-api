<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * The synchronous outbox writer the spec's atomic write requires at step 5.
 *
 * It must NOT implement ShouldQueue and must NOT use afterCommit. Spatie splits
 * handlers purely on `instanceof ShouldQueue`: sync handlers run inline inside
 * persist(), and a job is dispatched only if some handler IS ShouldQueue. Omitting
 * the interface is therefore exactly what keeps this inline and inside the caller's
 * transaction.
 *
 * SpikeProbeLog arrives by constructor injection from the container rather than
 * from the instance given to addProjector(), because Projectionist::callEventHandler()
 * re-resolves the handler with app($handler::class) and throws that instance away.
 *
 * The handling method is selected by parameter type, not by name: any public or
 * protected method whose parameter type extends ShouldBeStored is discovered.
 */
final class SpikeOutboxWriter extends Projector
{
    public function __construct(
        private readonly SpikeProbeLog $log,
        private readonly SpikeFailureSwitch $failure,
    ) {}

    public function onSpikePaymentInitiated(SpikePaymentInitiated $event): void
    {
        DB::connection(SpikeDatabase::CONNECTION)
            ->table('spike_payment_outbox')
            ->insert(['event_id' => $event->eventId]);

        $this->log->record('outbox-handler', SpikeDatabase::probe());

        // Trips only when a test armed the switch. By this point the stored event
        // AND this outbox row are both written, so a failure here proves all three
        // writes unwind together rather than only the two that preceded it.
        //
        // Both collaborators are container singletons, which is what makes this
        // reachable at all: this object was rebuilt by app() at dispatch time and
        // is not the instance the test registered.
        $this->failure->trip('inside outbox writer, after the outbox row');
    }
}
