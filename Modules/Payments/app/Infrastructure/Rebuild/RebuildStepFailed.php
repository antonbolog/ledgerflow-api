<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use Modules\Payments\Application\Rebuild\RebuildReason;
use RuntimeException;
use Throwable;

/**
 * One transactional step of the rebuild aborted, already classified.
 *
 * CLASSIFIED AT THE ABORT SITE, NOT AT THE TOP LEVEL. Whether a failure is an
 * unknown commit outcome depends on exactly which phase the connection and PDO
 * were in when it happened - the same reason the relay, the consumer and
 * targeted repair each classify inline rather than re-deriving it later from an
 * end state that cannot distinguish "never started" from "already durable".
 */
final class RebuildStepFailed extends RuntimeException
{
    public function __construct(public readonly RebuildReason $reason, Throwable $previous)
    {
        parent::__construct("Payments projection rebuild step aborted: {$reason->value}.", 0, $previous);
    }
}
