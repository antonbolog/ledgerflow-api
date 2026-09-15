<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

interface ProjectionVerifier
{
    public function verify(): VerificationReport;
}
