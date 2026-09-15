<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

use Modules\Payments\Infrastructure\Projection\PaymentProjectionConsumer;

// Wire contract for queued messages: keep this class name, constructor and single eventId property.
// Never implement ShouldQueueAfterCommit, declare $afterCommit, or dispatch with ->afterCommit(): publication must precede COMMIT.
final readonly class ProjectPaymentEvent
{
    public function __construct(public string $eventId) {}

    // Dependencies arrive through handle() so none is serialized; consumer exceptions must reach the worker.
    public function handle(PaymentProjectionConsumer $consumer): void
    {
        $consumer->project($this->eventId);
    }
}
