<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Weight 1 runs after PaymentOutboxWriter. Bind it as a container singleton: the projectionist re-resolves handlers through app().
 */
final class ThrowingProjector extends Projector
{
    public int $weight = 1;

    /** @var list<int> */
    public array $outboxRowsSeen = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function onPaymentInitiated(PaymentInitiated $event): never
    {
        $this->outboxRowsSeen[] = $this->connection->table('payment_outbox')->count();

        throw new RuntimeException('projector failed after the envelope was written');
    }
}
