<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

use LogicException;

/**
 * A report was built as complete without every required pass having run.
 *
 * A PROGRAMMING ERROR, NOT A DATABASE ONE, which is why it is a LogicException and
 * why the verifier does not catch it into an incomplete report. A run that lost a
 * pass to an early return has a defect in the audit, and turning that into a
 * routine "incomplete" answer would hide the defect behind the same word an
 * ordinary timeout uses.
 */
final class IncompleteVerificationScope extends LogicException {}
