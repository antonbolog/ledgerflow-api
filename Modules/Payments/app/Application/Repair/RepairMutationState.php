<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

enum RepairMutationState: string
{
    case None = 'none';

    case Created = 'created';

    case Repaired = 'repaired';

    case NoChanges = 'no_changes';

    case RolledBack = 'rolled_back';

    case Unknown = 'unknown';

    case Committed = 'committed';
}
