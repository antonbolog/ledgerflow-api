<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;

final class SqlStates
{
    public static function conflicting(string $errorInfoState, string $codeState): PDOException
    {
        $exception = new StringCodePdoException('conflicting sqlstate channels', $codeState);
        $exception->errorInfo = [$errorInfoState, 7, 'driver detail'];

        return $exception;
    }

    public static function fromErrorInfo(string $sqlState): PDOException
    {
        $exception = new PDOException('driver reported '.$sqlState);
        $exception->errorInfo = [$sqlState, 7, 'driver detail'];

        return $exception;
    }

    public static function fromCodeOnly(string $sqlState): PDOException
    {
        return new StringCodePdoException('driver reported '.$sqlState, $sqlState);
    }

    public static function withoutState(): PDOException
    {
        return new PDOException('a driver failure carrying no sqlstate');
    }

    public static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'pgsql',
            'insert into "payment_idempotency_receipts" ("id") values (?)',
            ['01984f6f-1111-7000-8000-000000000001'],
            self::conflicting('23505', '23505'),
        );
    }
}
