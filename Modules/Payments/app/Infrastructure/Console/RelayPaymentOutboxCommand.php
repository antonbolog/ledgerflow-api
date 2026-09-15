<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Modules\Payments\Infrastructure\Relay\OutboxRelay;
use Modules\Payments\Infrastructure\Relay\RelayConfiguration;
use Modules\Payments\Infrastructure\Relay\RelayHeartbeat;
use Modules\Payments\Infrastructure\Relay\RelayOutcome;
use Psr\Log\LoggerInterface;
use Throwable;

final class RelayPaymentOutboxCommand extends Command
{
    protected $signature = 'payments:outbox:relay';

    protected $description = 'Publish one bounded batch of eligible payment_outbox rows to the projection queue';

    /**
     * @param  Closure(): int  $monotonic  nanoseconds from an arbitrary origin, never a wall clock
     */
    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly RelayConfiguration $config,
        private readonly RelayHeartbeat $heartbeat,
        private readonly LoggerInterface $logger,
        private readonly Closure $monotonic,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = ($this->monotonic)();
        $budgetNanoseconds = $this->config->startBudgetMs * 1_000_000;

        $attempted = 0;
        $published = 0;
        $rejected = 0;
        $stopReason = 'drained';

        try {
            while (true) {
                // A start budget, not a kill deadline: a row already begun always finishes.
                if ($attempted > 0) {
                    if ($attempted >= $this->config->batchSize) {
                        $stopReason = 'batch';

                        break;
                    }

                    if ((($this->monotonic)() - $startedAt) >= $budgetNanoseconds) {
                        $stopReason = 'budget';

                        break;
                    }
                }

                $outcome = $this->relay->relayOne();

                if ($outcome === RelayOutcome::NothingEligible) {
                    $stopReason = 'drained';

                    break;
                }

                if ($outcome === RelayOutcome::GateClosed) {
                    $stopReason = 'gate_closed';

                    break;
                }

                $attempted++;

                match ($outcome) {
                    RelayOutcome::Published => $published++,
                    RelayOutcome::TransportRejected => $rejected++,
                };
            }
        } catch (Throwable $failure) {
            // No heartbeat: a failing pass must not report a healthy scheduler.
            $this->logger->error('Payments outbox relay: the pass was aborted.', [
                'category' => 'relay_pass_aborted',
                'exception_class' => $failure::class,
                'attempted' => $attempted + 1,
                'published' => $published,
                'rejected' => $rejected,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            return self::FAILURE;
        }

        $this->heartbeat->beat();

        $this->logger->info('Payments outbox relay: pass completed.', [
            'category' => 'relay_pass_completed',
            'attempted' => $attempted,
            'published' => $published,
            'rejected' => $rejected,
            'duration_ms' => $this->elapsedMs($startedAt),
            'stop_reason' => $stopReason,
        ]);

        return self::SUCCESS;
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(($this->monotonic)() - $startedAt, 1_000_000);
    }
}
