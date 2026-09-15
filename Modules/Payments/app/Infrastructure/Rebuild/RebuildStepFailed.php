<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use Modules\Payments\Application\Rebuild\RebuildReason;
use RuntimeException;
use Throwable;

final class RebuildStepFailed extends RuntimeException
{
    public function __construct(public readonly RebuildReason $reason, Throwable $previous)
    {
        parent::__construct("Payments projection rebuild step aborted: {$reason->value}.", 0, $previous);
    }
}
