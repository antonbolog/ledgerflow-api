<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

interface OutboxRepair
{
    public function repair(string $eventId, bool $deliveryQuiesced, string $operationId): RepairOutcome;
}
