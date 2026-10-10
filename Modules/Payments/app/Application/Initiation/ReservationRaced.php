<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;
use Throwable;

final class ReservationRaced extends RuntimeException
{
    public static function onAnotherUniqueKey(?Throwable $previous = null): self
    {
        return new self('The intent reservation collided with another unique receipt key.', 0, $previous);
    }
}
