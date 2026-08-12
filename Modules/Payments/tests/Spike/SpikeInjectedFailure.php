<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use RuntimeException;

/**
 * The only exception the spike injects on purpose.
 *
 * A dedicated type rather than a bare RuntimeException, so a rollback test can
 * assert it caught the failure it planted and not, say, the receipt-reservation
 * guard firing because stale rows survived. Two different RuntimeExceptions
 * reaching the same assertion would look identical.
 *
 * It extends RuntimeException, and therefore Exception, deliberately:
 * Projectionist::callEventHandler() catches `Exception`, so this is exactly the
 * kind of failure that catch_exceptions would swallow. An Error subclass would
 * sail past that catch and prove nothing about the swallowing behaviour.
 */
final class SpikeInjectedFailure extends RuntimeException {}
