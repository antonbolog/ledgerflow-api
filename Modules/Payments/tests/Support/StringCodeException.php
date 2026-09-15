<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use RuntimeException;

// A non-database exception with a SQLSTATE-shaped string code: an int code cannot show whether the PDOException guard exists.
final class StringCodeException extends RuntimeException
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message);

        $this->code = $code;
    }
}
