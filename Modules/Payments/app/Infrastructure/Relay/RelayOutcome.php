<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Relay;

enum RelayOutcome: string
{
    case NothingEligible = 'nothing_eligible';

    case Published = 'published';

    case TransportRejected = 'transport_rejected';

    case GateClosed = 'gate_closed';
}
