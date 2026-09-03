<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

/**
 * The read-only audit of Payments derived state, asked for one report.
 *
 * A PORT FOR THE REASON PaymentQuery IS ONE: Modules\Payments\Application may
 * not name a framework type, so nothing about connections, isolation levels or
 * cursors appears in this signature. The snapshot, the batching and the SQL are
 * an adapter's problem; the contract here is a report.
 *
 * NO ARGUMENTS, AND THAT IS THE CONTRACT. Every bound the audit runs under is
 * configuration validated before it starts, not a per-call parameter a caller
 * could widen. An audit whose batch size or example cap were caller-supplied
 * would have no bound at all.
 *
 * IT NEVER THROWS FOR AN AUDIT THAT DID NOT FINISH. A timeout, a lost
 * connection or an uninspectable payload are outcomes this audit has to be able
 * to REPORT — with the findings it had already observed, and explicitly marked
 * incomplete — rather than incidents that erase them. See
 * VerificationReport::incomplete().
 */
interface ProjectionVerifier
{
    public function verify(): VerificationReport;
}
