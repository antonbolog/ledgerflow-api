<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Rebuild;

interface ProjectionRebuild
{
    public function rebuild(bool $confirmRebuild, string $operationId): RebuildOutcome;
}
