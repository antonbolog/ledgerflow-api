<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

final class ProjectionRebuilding extends RuntimeException
{
    public static function duringInitiation(): self
    {
        return new self('The payment_details projection is being rebuilt; initiation is deferred.');
    }
}
