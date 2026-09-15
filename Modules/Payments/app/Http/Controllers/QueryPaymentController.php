<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Query\ProjectedPayment;

final class QueryPaymentController
{
    public function __invoke(string $paymentId, MerchantContext $merchants, PaymentQuery $payments): JsonResponse
    {
        $found = $payments->findOwned($merchants->currentMerchantId(), $paymentId);

        if ($found instanceof ProjectedPayment) {
            // Literal keys in wire order, so a new ProjectedPayment field cannot reach clients implicitly.
            return response()->json([
                'payment_id' => $found->paymentId,
                'amount_minor' => $found->amountMinor,
                'currency' => $found->currency,
                'status' => $found->status,
                'initiated_at' => $found->initiatedAt,
            ]);
        }

        return response()
            ->json(['payment_id' => $found->paymentId, 'status' => 'projection_pending'], 202)
            ->withHeaders(['Retry-After' => '1']);
    }
}
