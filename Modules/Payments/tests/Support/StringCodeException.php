<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use RuntimeException;

/**
 * A NON-database exception whose `code` happens to be a SQLSTATE-shaped string.
 *
 * This is the shape that makes `! $e instanceof PDOException` load-bearing. An int
 * code is rejected by the `is_string($code)` fallback anyway, so a plain
 * RuntimeException cannot tell whether the type guard is present. A string code
 * can: without the guard, `sqlStateOf()` reads it and the runner retries an
 * exception that never came from a database at all.
 *
 * Not hypothetical in kind — Exception::$code is untyped on the base class, and
 * any library is free to assign a string to it.
 */
final class StringCodeException extends RuntimeException
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message);

        $this->code = $code;
    }
}
