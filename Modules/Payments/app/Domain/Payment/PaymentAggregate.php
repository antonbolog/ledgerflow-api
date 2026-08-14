<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment;

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Modules\Payments\Domain\Payment\Exceptions\PaymentAlreadyInitiated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * The Payments write model. One transition in this slice: nothing exists, then
 * the payment is initiated.
 *
 * NO COMMAND METHOD ACCEPTS A PaymentInitiated, deliberately. AggregateRoot::apply()
 * invokes every public or protected method whose parameter accepts the event,
 * excluding only handleCommand, recordThat, apply and tap. A command method typed
 * against the event would be re-invoked during replay, and if it called recordThat()
 * the pair would recurse. initiate() takes scalars, which apply() cannot match.
 *
 * Authorized, Captured, Settled, Failed and Refunded are NOT here. The lifecycle
 * is documented in AGENTS.md; inventing its transitions before a use case needs
 * them is schema by anticipation.
 */
final class PaymentAggregate extends AggregateRoot
{
    private bool $initiated = false;

    public function initiate(
        string $eventId,
        string $paymentId,
        int $merchantId,
        int $amountMinor,
        string $currency,
        string $occurredAt,
    ): static {
        if ($this->initiated) {
            throw PaymentAlreadyInitiated::for($paymentId);
        }

        // The uuid is empty on an aggregate built with `new`, which the pure
        // domain tests rely on. When Spatie has bound one, a disagreeing
        // paymentId would file a stored event under one aggregate stream while
        // its payload claimed another payment.
        if ($this->uuid() !== '' && $this->uuid() !== $paymentId) {
            throw InvalidPaymentInitiation::paymentIdDoesNotMatchAggregate($paymentId, $this->uuid());
        }

        return $this->recordThat(new PaymentInitiated(
            eventId: $eventId,
            paymentId: $paymentId,
            merchantId: $merchantId,
            amountMinor: $amountMinor,
            currency: $currency,
            occurredAt: $occurredAt,
        ));
    }

    /**
     * Discovered by parameter type, not by name. $event is unused because the
     * only state this slice needs is "has it happened", but the type is
     * load-bearing: it is how Spatie selects this method.
     */
    protected function applyPaymentInitiated(PaymentInitiated $event): void
    {
        $this->initiated = true;
    }
}
