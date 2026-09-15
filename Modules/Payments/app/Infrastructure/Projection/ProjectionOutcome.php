<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

enum ProjectionOutcome: string
{
    case Projected = 'projected';

    case AlreadyProjected = 'already_projected';

    case MarkerRepaired = 'marker_repaired';

    case SkippedExhausted = 'skipped_exhausted';

    case GateDeferred = 'gate_deferred';
}
