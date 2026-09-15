<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use PDOException;

// PDO sets string SQLSTATE codes, which Exception::__construct() cannot accept.
final class StringCodePdoException extends PDOException
{
    public function __construct(string $message, string $sqlState)
    {
        parent::__construct($message);

        $this->code = $sqlState;
    }
}
