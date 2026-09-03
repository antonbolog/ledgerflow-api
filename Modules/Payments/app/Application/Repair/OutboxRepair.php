<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

/**
 * Reconstruct or correct exactly one derived outbox envelope from canonical
 * history.
 *
 * A PORT FOR THE REASON PaymentQuery AND ProjectionVerifier ARE ONES: nothing
 * about connections, locks or isolation appears in this signature. The
 * transaction, the canonical row lock and the SQL are an adapter's problem.
 *
 * IT TAKES THE ACKNOWLEDGEMENT AS AN ARGUMENT RATHER THAN READING A FLAG. Repair
 * is an offline delivery operation, and the operator's assertion that delivery is
 * stopped is part of the request - recorded in the audit, and never treated as
 * machine-verified proof of global quiescence. A port that read the console's
 * options itself would make that assertion invisible to any other caller.
 *
 * $operationId CORRELATES INTENT WITH OUTCOME. It is minted by the caller before
 * anything is attempted, so the record of what was asked for exists even when the
 * record of what happened does not.
 *
 * IT NEVER THROWS FOR A REPAIR THAT REFUSED OR FAILED. An ambiguous history, a
 * lost connection or an unknown commit are outcomes an operator has to be able to
 * READ, with the mutation state attached, rather than incidents that arrive as an
 * exception carrying a driver's wording.
 */
interface OutboxRepair
{
    public function repair(string $eventId, bool $deliveryQuiesced, string $operationId): RepairOutcome;
}
