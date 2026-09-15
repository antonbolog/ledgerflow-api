<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Payments\Application\Initiation\InitiatePayment;
use Modules\Payments\Application\Initiation\PaymentInitiationContext;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Http\Requests\InitiatePaymentRequest;

final class InitiatePaymentController
{
    public function __invoke(
        InitiatePaymentRequest $request,
        MerchantContext $merchants,
        InitiatePayment $initiate,
    ): Response {
        $merchantId = $merchants->currentMerchantId();
        $amountMinor = $request->canonicalAmountMinor();
        $currency = $request->canonicalCurrency();

        // Built before the transaction: every retry must reuse identical ids, time and response bytes.
        $context = new PaymentInitiationContext(
            merchantId: $merchantId,
            paymentId: (string) Str::uuid7(),
            eventId: (string) Str::uuid7(),
            amountMinor: $amountMinor,
            currency: $currency,
            occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
            idempotencyKeyHash: PaymentInitiationContext::hashKey($request->rawIdempotencyKey()),
            requestFingerprint: PaymentInitiationContext::fingerprint($merchantId, $amountMinor, $currency),
        );

        $receipt = $initiate($context);

        return response($receipt->responseBody, $receipt->responseStatus)
            ->header('Content-Type', 'application/json')
            ->header('Location', $receipt->responseLocation);
    }
}
