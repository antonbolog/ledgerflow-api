<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Projection;

use RuntimeException;

// Never swallow: acknowledging would hide that the transport and PostgreSQL disagree.
final class ProjectionEnvelopeMissing extends RuntimeException
{
    public const string CATEGORY = 'projection_envelope_missing';

    public function __construct(public readonly string $eventId)
    {
        parent::__construct(
            "No payment_outbox row exists for delivered event {$eventId}. The transport carried an "
            .'identifier PostgreSQL does not know, so there is nothing to project and nothing to '
            .'charge. Failing visibly rather than acknowledging, so the disagreement is not hidden.'
        );
    }
}
