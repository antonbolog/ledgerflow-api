<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

enum ProjectionGateState: string
{
    case Open = 'open';

    case ClosedByRebuildLock = 'closed_by_rebuild_lock';

    case ClosedByControlState = 'closed_by_control_state';

    case ClosedByMissingControlRow = 'closed_by_missing_control_row';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
