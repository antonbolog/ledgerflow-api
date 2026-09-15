<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use RuntimeException;

// Never a finding: it describes the audit's own failure, not corrupt payment data.
final class VerificationSnapshotUnavailable extends RuntimeException {}
