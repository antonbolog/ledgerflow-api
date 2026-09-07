<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Rebuild;

use RuntimeException;

/**
 * The ownership fence refused a write, at one of its three checkpoints.
 *
 * A MARKER, NOT A DATABASE FAILURE. Raised from inside one of the rebuild's own
 * transactions when the fence query itself succeeds but answers "no": either
 * this run's token no longer matches the control row (a takeover happened) or
 * this backend no longer holds the exclusive lock (F3's silent reconnect).
 * DatabaseProjectionRebuild's commit-boundary classifier treats it as a clean,
 * unambiguous abort - never as an unknown commit outcome - because nothing was
 * ever attempted to be written in the transaction that raised it.
 */
final class RebuildFenceFailed extends RuntimeException
{
    public function __construct(string $checkpoint)
    {
        parent::__construct("The projection rebuild ownership fence refused a write at: {$checkpoint}.");
    }
}
