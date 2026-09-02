<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Repair;

/**
 * What happened to the database, as far as this process can honestly tell.
 *
 * IT IS NOT THE SAME QUESTION AS "did the command succeed". A repair can commit
 * and then fail to record that it did; an operator reading `committed` beside a
 * non-zero exit knows the row changed and the audit did not, which is exactly the
 * state that must not be reported as either a clean success or a clean failure.
 *
 * `Unknown` IS A REAL ANSWER AND THE MOST IMPORTANT ONE. A COMMIT whose outcome
 * never came back may or may not be durable, and nothing here may guess. The
 * contract is to say so and stop: recovery is a fresh verification run, never an
 * automatic second write.
 */
enum RepairMutationState: string
{
    /** Nothing was attempted. Every refusal ends here. */
    case None = 'none';

    /** One envelope was inserted, and its delivery history could not be recovered. */
    case Created = 'created';

    /** An existing envelope was corrected in place. */
    case Repaired = 'repaired';

    /** The envelope already matched canonical history. Nothing was written. */
    case NoChanges = 'no_changes';

    /** The work was rolled back, and the rollback itself was confirmed. */
    case RolledBack = 'rolled_back';

    /** COMMIT was attempted and its outcome is not known. */
    case Unknown = 'unknown';

    /** COMMIT was confirmed. Whatever failed afterwards did not undo it. */
    case Committed = 'committed';
}
