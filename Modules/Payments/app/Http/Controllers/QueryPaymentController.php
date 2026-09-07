<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Query\ProjectedPayment;

/**
 * GET /api/payments/{paymentId}.
 *
 * THE MERCHANT IS RESOLVED ONCE, AND THE PATH SEGMENT IS THE ONLY OTHER INPUT.
 * MerchantContext reads the real Sanctum guard on every call and caches nothing,
 * so asking twice would be two guard resolutions for one question. There is no
 * merchant parameter, query string or header this endpoint will read instead.
 *
 * IT DECIDES NOTHING ABOUT NOT-FOUND. Malformed, unknown and foreign ids all leave
 * the port as PaymentNotFound, and ProblemDetailsMapper renders that one exception
 * type as the one 404 document. A branch here would be a second place for the
 * three answers to drift apart.
 *
 * NO 503 BRANCH HERE EITHER. A closed projection gate leaves the port as
 * ProjectionRebuilding, and ProblemDetailsMapper renders it - this controller
 * still decides nothing about it.
 *
 * The bodies below ARE the contract, pinned in the projection and query
 * specification. Keys are written literally and in wire order rather than derived
 * from the result object, so a field added to ProjectedPayment cannot reach a
 * client by arriving in a serializer nobody re-read.
 */
final class QueryPaymentController
{
    public function __invoke(string $paymentId, MerchantContext $merchants, PaymentQuery $payments): JsonResponse
    {
        $found = $payments->findOwned($merchants->currentMerchantId(), $paymentId);

        if ($found instanceof ProjectedPayment) {
            return response()->json([
                'payment_id' => $found->paymentId,
                'amount_minor' => $found->amountMinor,
                'currency' => $found->currency,
                'status' => $found->status,
                'initiated_at' => $found->initiatedAt,
            ]);
        }

        // 202, NOT 404 AND NOT AN ERROR DOCUMENT. The payment is durably committed;
        // only the read model lags. Retry-After: 1 is the whole guidance a client
        // needs, and `projection_pending` is transport state rather than a payment
        // status the domain ever records.
        return response()
            ->json(['payment_id' => $found->paymentId, 'status' => 'projection_pending'], 202)
            ->withHeaders(['Retry-After' => '1']);
    }
}
