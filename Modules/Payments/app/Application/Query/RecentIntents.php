<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Query;

final readonly class RecentIntents
{
    /**
     * @param  list<ProjectedIntent|PendingIntent>  $intents  newest first
     */
    public function __construct(
        public array $intents,
        public bool $truncated,
    ) {}
}
