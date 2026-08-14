<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use Closure;

/**
 * The single outer transaction the command handler owns, with the retry policy
 * attached.
 *
 * A port for the same boundary reason as IdempotencyReceipts. Closure is a
 * single-segment global name, which the boundary scanner skips, so this interface
 * stays framework-free.
 */
interface AtomicTransaction
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function run(Closure $work): mixed;
}
