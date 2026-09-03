<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Verification;

use RuntimeException;

/**
 * The audit could not establish the snapshot it was going to report on.
 *
 * SEPARATE FROM A DECODING REFUSAL, and deliberately: a malformed event is a
 * FINDING about history, while this says the audit itself has no ground to stand
 * on. The verifier catches it like any other failure and reports an incomplete
 * run - it must never become a finding, or "the database answered strangely"
 * would be recorded as corrupt payment data.
 */
final class VerificationSnapshotUnavailable extends RuntimeException {}
