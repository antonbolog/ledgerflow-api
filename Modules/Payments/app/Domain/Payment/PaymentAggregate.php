<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Payment;

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Domain\Payment\Exceptions\InvalidPaymentInitiation;
use Modules\Payments\Domain\Payment\Exceptions\PaymentAlreadyInitiated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

// Command methods take scalars: AggregateRoot::apply() invokes every method accepting the event, including on replay.
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

        // uuid() is empty on an aggregate built with `new`, as in the framework-free domain tests.
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

    // Spatie selects apply methods by parameter type, so the unused $event must stay typed.
    protected function applyPaymentInitiated(PaymentInitiated $event): void
    {
        $this->initiated = true;
    }
}
