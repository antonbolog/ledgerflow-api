<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * Test-only stand-in for the real PaymentAggregate, which T5 owns.
 *
 * NO COMMAND METHOD ACCEPTS SpikePaymentInitiated, deliberately.
 *
 * AggregateRoot::applyStoredEvent() calls every public or protected method whose
 * parameter accepts the event, excluding only the methods literally named `apply`
 * and `recordThat`. A command method such as `initiate(SpikePaymentInitiated $e)`
 * would therefore be invoked during apply(), and if it called recordThat() the
 * pair would recurse until the stack blew. The handler calls recordThat() directly
 * instead, which is the base-class API and is excluded by name.
 *
 * applySpikePaymentInitiated() below IS matched by that discovery, which is the
 * legitimate Spatie pattern and the reason the counter proves apply() actually ran.
 */
final class SpikePaymentAggregate extends AggregateRoot
{
    public int $initiatedCount = 0;

    protected function applySpikePaymentInitiated(SpikePaymentInitiated $event): void
    {
        $this->initiatedCount++;
    }
}
