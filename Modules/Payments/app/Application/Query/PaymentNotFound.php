<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

use RuntimeException;

/**
 * One answer for three questions: the id is malformed, no such payment exists, or
 * it belongs to another merchant.
 *
 * A RuntimeException, not a LogicException, for the reason
 * IdempotencyPayloadMismatch gives: the client caused a legitimate runtime
 * condition, not a programming error.
 *
 * IT NAMES NOTHING. No payment id, no merchant id, no ownership verdict, no hint
 * that the id was syntactically invalid. The message is reported and logged, and
 * a message that distinguished "not yours" from "not there" would leak the
 * existence of another merchant's payment through the log as surely as through
 * the response body. That is why this exception has no arguments at all.
 */
final class PaymentNotFound extends RuntimeException
{
    public static function forRequestedPayment(): self
    {
        return new self('No payment matching the requested identifier is visible to this merchant.');
    }
}
