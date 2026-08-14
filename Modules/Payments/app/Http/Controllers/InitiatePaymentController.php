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

/**
 * POST /api/payments.
 *
 * THE COMMAND CONTEXT IS BUILT HERE, BEFORE THE TRANSACTION, AND THAT PLACEMENT IS
 * THE CONTRACT. Identifiers, the clock reading, both hashes and the exact response
 * bytes are minted once, in the HTTP layer, and handed to the use case as an
 * immutable value. The retry loop inside AtomicTransaction therefore has nothing to
 * regenerate: byte-stability across attempts is structural, not a rule someone has
 * to remember.
 *
 * The HTTP layer is also the only layer allowed to do this. Str and CarbonImmutable
 * are framework types, which the module boundary rule forbids in Application — so
 * "generate it before the transaction" and "generate it where the framework lives"
 * are the same instruction here.
 *
 * The merchant comes from MerchantContext and nowhere else. InitiatePaymentRequest
 * refuses a client-supplied merchant_id outright rather than overruling it, so
 * there is no path by which the body can influence ownership.
 */
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

        $context = new PaymentInitiationContext(
            merchantId: $merchantId,
            paymentId: (string) Str::uuid7(),
            eventId: (string) Str::uuid7(),
            amountMinor: $amountMinor,
            currency: $currency,
            occurredAt: CarbonImmutable::now()->utc()->format(PaymentInitiated::OCCURRED_AT_FORMAT),
            // The RAW key is read exactly once, here, and only its digest travels on.
            idempotencyKeyHash: PaymentInitiationContext::hashKey($request->rawIdempotencyKey()),
            requestFingerprint: PaymentInitiationContext::fingerprint($merchantId, $amountMinor, $currency),
        );

        $receipt = $initiate($context);

        // The STORED bytes, verbatim. On a replay these came out of the receipt row
        // rather than out of this request, which is what makes an identical retry
        // byte-identical rather than merely equivalent.
        return response($receipt->responseBody, $receipt->responseStatus)
            // Explicit: a string response would otherwise be sent as text/html.
            ->header('Content-Type', 'application/json')
            ->header('Location', $receipt->responseLocation);
    }
}
