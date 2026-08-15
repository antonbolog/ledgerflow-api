<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use PDOException;

/**
 * A PDOException whose `code` is a STRING, as PDO's own are.
 *
 * Exception::__construct() types $code as int, so the driver's real shape — a
 * five-character SQLSTATE in `code` — cannot be built through the constructor.
 * Assigning the protected property from a subclass reproduces it exactly, and
 * reproducing it is the point: RetryingAtomicTransaction::sqlStateOf() compares
 * against strings, and a test that could only produce an int code would pass
 * against a runner that compared against ints too.
 */
final class StringCodePdoException extends PDOException
{
    public function __construct(string $message, string $sqlState)
    {
        parent::__construct($message);

        $this->code = $sqlState;
    }
}
