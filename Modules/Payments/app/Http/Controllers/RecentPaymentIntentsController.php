<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Payments\Application\Query\PaymentQuery;
use Modules\Payments\Application\Query\PendingIntent;
use Modules\Payments\Application\Query\ProjectedIntent;

final class RecentPaymentIntentsController
{
    public function __invoke(MerchantContext $merchants, PaymentQuery $payments): JsonResponse
    {
        $recent = $payments->recentIntents($merchants->currentMerchantId());

        // Literal keys in wire order, so a new result field cannot reach clients implicitly.
        return response()->json([
            'intents' => array_map(
                static fn (ProjectedIntent|PendingIntent $intent): array => $intent instanceof ProjectedIntent
                    ? [
                        'intent_id' => $intent->intentId,
                        'payment_id' => $intent->paymentId,
                        'created_at' => $intent->createdAt,
                        'amount_minor' => $intent->amountMinor,
                        'currency' => $intent->currency,
                    ]
                    : [
                        'intent_id' => $intent->intentId,
                        'payment_id' => $intent->paymentId,
                        'created_at' => $intent->createdAt,
                    ],
                $recent->intents,
            ),
            'truncated' => $recent->truncated,
        ]);
    }
}
