<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Initiation;

use Closure;

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
