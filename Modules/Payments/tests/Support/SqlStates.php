<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;

/**
 * Exception shapes the PostgreSQL driver actually produces, built by hand.
 *
 * NO REAL 55P03, 40P01 OR 40001 IS RAISED ANYWHERE IN THIS SUITE'S UNIT TESTS.
 * Provoking a real lock conflict proves the DRIVER reports it; these tests are
 * about what the RUNNER does with the report. T3 and T4 already measured the real
 * article, and Slice 4 owns the live 55P03 — re-staging one here would buy a slower
 * suite and the same information.
 *
 * WHY A SUBCLASS FOR THE CODE-ONLY SHAPE: PDO assigns the SQLSTATE to the exception
 * `code` as a STRING, but Exception::__construct() types that parameter as int, so
 * a plain `new PDOException($m, '23505')` is impossible. The property is protected
 * on Exception, so a subclass is the only honest way to reproduce what the driver
 * hands the application.
 */
final class SqlStates
{
    /** Both channels populated and DISAGREEING, so precedence is observable. */
    public static function conflicting(string $errorInfoState, string $codeState): PDOException
    {
        $exception = new StringCodePdoException('conflicting sqlstate channels', $codeState);
        $exception->errorInfo = [$errorInfoState, 7, 'driver detail'];

        return $exception;
    }

    /** errorInfo populated, code left at PDO's default. */
    public static function fromErrorInfo(string $sqlState): PDOException
    {
        $exception = new PDOException('driver reported '.$sqlState);
        $exception->errorInfo = [$sqlState, 7, 'driver detail'];

        return $exception;
    }

    /** code populated, errorInfo absent — the fallback channel. */
    public static function fromCodeOnly(string $sqlState): PDOException
    {
        return new StringCodePdoException('driver reported '.$sqlState, $sqlState);
    }

    /** No SQLSTATE in either channel. */
    public static function withoutState(): PDOException
    {
        return new PDOException('a driver failure carrying no sqlstate');
    }

    /**
     * A 23505 in the shape T3 measured: a UniqueConstraintViolationException whose
     * errorInfo and code both come from the wrapped PDOException.
     */
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
