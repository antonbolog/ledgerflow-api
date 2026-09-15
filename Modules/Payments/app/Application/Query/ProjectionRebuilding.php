<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

use RuntimeException;

final class ProjectionRebuilding extends RuntimeException
{
    public static function forRequestedPayment(): self
    {
        return new self('The payment_details projection is being rebuilt; this read is deferred.');
    }
}
