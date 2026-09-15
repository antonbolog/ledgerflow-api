<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use RuntimeException;

// A clean abort, never an unknown commit outcome: the raising transaction attempted no write.
final class RebuildFenceFailed extends RuntimeException
{
    public function __construct(string $checkpoint)
    {
        parent::__construct("The projection rebuild ownership fence refused a write at: {$checkpoint}.");
    }
}
