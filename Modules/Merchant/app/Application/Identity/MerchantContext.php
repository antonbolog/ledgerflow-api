<?php

declare(strict_types=1);

namespace Modules\Merchant\Application\Identity;

/**
 * The merchant that owns the work being done for the current request.
 *
 * Consumers ask this instead of reading a request parameter, which makes "the
 * merchant comes from the authenticated user; the client cannot choose it"
 * (docs/architecture/payments-flow.md) one enforced rule rather than a
 * convention repeated at every endpoint.
 *
 * Application, not Domain: Domain has no framework and no ambient request. The
 * implementation that knows about guards and tokens lives in Infrastructure,
 * and Payments reaches this namespace and no deeper.
 */
interface MerchantContext
{
    /** @throws NoAuthenticatedMerchantUser when no merchant user is authenticated */
    public function currentMerchantId(): int;
}
