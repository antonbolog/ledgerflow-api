<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use RuntimeException;

final class PaymentIntentDeadlineRefused extends RuntimeException
{
    /** @param  int  $millisecondsUntilSettled  PostgreSQL time left until the expiry plus the residual allowance */
    private function __construct(string $message, public readonly int $millisecondsUntilSettled)
    {
        parent::__construct($message);
    }

    public static function beforeWork(int $millisecondsUntilSettled): self
    {
        return new self('The payment intent deadline left no time to start creating work.', $millisecondsUntilSettled);
    }

    public static function beforeCommit(int $millisecondsUntilSettled): self
    {
        return new self('The payment intent deadline left no time to commit.', $millisecondsUntilSettled);
    }
}
