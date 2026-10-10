<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Modules\Payments\Infrastructure\Persistence\DatabasePaymentIntentDeadline;
use RuntimeException;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

/**
 * Holds a real attempt after its writes until the expiry is inside the refusal margin, as slow work would, so the
 * pre-COMMIT re-check meets a spent deadline on a still-live session. Polls the observer's clock rather than sleeping.
 */
final class DeadlineMarginProbe extends Projector
{
    public int $weight = 3;

    public string $intentExpiresAt = '';

    public function onPaymentInitiated(PaymentInitiated $event): void
    {
        $giveUpAt = hrtime(true) + 5_000_000_000;

        while (! $this->insideMargin()) {
            if (hrtime(true) > $giveUpAt) {
                throw new RuntimeException('the deadline never came inside the margin');
            }

            Sleep::usleep(5_000);
        }
    }

    private function insideMargin(): bool
    {
        $row = DB::connection(IntegrationDatabase::OBSERVER)->selectOne(
            'select (floor(extract(epoch from (?::timestamptz - clock_timestamp())) * 1000) <= ?)::int as inside',
            [$this->intentExpiresAt, DatabasePaymentIntentDeadline::MARGIN_MS],
        );

        return (int) $row->inside === 1;
    }
}
