<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * A synchronous projector that fails AFTER the production writer has run.
 *
 * $weight = 1 puts it behind PaymentOutboxWriter's implicit 0.
 * EventHandlerCollection::syncEventHandlers() sorts handlers by getWeight(), so the
 * ordering is the package's own mechanism and not an accident of which projector
 * was registered first.
 *
 * IT MUST BE A CONTAINER SINGLETON. Projectionist::callEventHandler() re-resolves
 * every handler with app($handler::class) and throws the registered instance away,
 * so a plain `new` would record into an object the test never sees again — the trap
 * T3 hit. Registering by class name plus a singleton binding is what makes
 * outboxRowsSeen readable afterwards.
 *
 * It records BEFORE it throws, so the test can prove the envelope really existed at
 * the moment of failure rather than assuming it from the weight.
 */
final class ThrowingProjector extends Projector
{
    public int $weight = 1;

    /** @var list<int> */
    public array $outboxRowsSeen = [];

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * `never`, not `void`: it records and then always throws, and the type says so.
     * The base Projector declares no such method, so there is no signature to widen.
     */
    public function onPaymentInitiated(PaymentInitiated $event): never
    {
        $this->outboxRowsSeen[] = $this->connection->table('payment_outbox')->count();

        throw new RuntimeException('projector failed after the envelope was written');
    }
}
