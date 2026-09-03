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

/**
 * One bounded relay pass, scheduled every second.
 *
 * IT OWNS THREE THINGS OutboxRelay DOES NOT: how many rows one invocation may
 * start, when it stops starting them, and whether the pass counted as healthy
 * progress. The relay owns one row; this owns the pass.
 *
 * A START BUDGET, NOT A KILL DEADLINE. The budget is checked BEFORE each next row,
 * never during one. A row already begun always finishes committing or rolling back
 * — abandoning it mid-transaction is what would leave a lock held and a connection
 * unusable — and the per-row statement and transport timeouts are what bound that
 * final attempt.
 *
 * IT WRITES NO CONSOLE OUTPUT ON THE HAPPY PATH. At one invocation per second,
 * inside `schedule:work`, anything printed here would be a flood. The pass summary
 * goes to the structured log, and the exit code is the machine-readable answer.
 */
final class RelayPaymentOutboxCommand extends Command
{
    protected $signature = 'payments:outbox:relay';

    protected $description = 'Publish one bounded batch of eligible payment_outbox rows to the projection queue';

    /**
     * @param  Closure(): int  $monotonic  nanoseconds from an arbitrary origin
     *
     * A MONOTONIC SOURCE, NOT A WALL CLOCK. The budget must survive an NTP step or
     * a daylight-saving jump; a wall clock going backwards mid-pass would make the
     * elapsed time negative and the budget effectively infinite. It is injected so
     * the bound can be proved without a pass that genuinely takes 750 ms.
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
                // BEFORE EACH NEXT ROW, and only once a row has been handled: a pass
                // always gets one attempt, so a scheduler running slightly late still
                // makes progress instead of spinning through empty budget checks.
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

                // NothingEligible is the only outcome that is not a handled row, so
                // it does not count as an attempt and it ends the pass.
                if ($outcome === RelayOutcome::NothingEligible) {
                    $stopReason = 'drained';

                    break;
                }

                // A DISTINCT STOP REASON FROM 'drained', DELIBERATELY. T11's gate was
                // closed before eligibility was even selected; an operator reading
                // the pass log must be able to tell "backlog is empty" apart from
                // "a rebuild is in progress" - see RelayOutcome::GateClosed.
                if ($outcome === RelayOutcome::GateClosed) {
                    $stopReason = 'gate_closed';

                    break;
                }

                $attempted++;

                match ($outcome) {
                    RelayOutcome::Published => $published++,
                    // Retryable and already durable in available_at, so another row
                    // may still be attempted while budget remains. The only other
                    // case, NothingEligible, already broke out of the loop above.
                    RelayOutcome::TransportRejected => $rejected++,
                };
            }
        } catch (Throwable $failure) {
            // NO HEARTBEAT ON THIS PATH. A heartbeat refreshed by a failing pass
            // would report a healthy scheduler for as long as the failure lasted,
            // which is the exact confusion the signal exists to prevent.
            //
            // OutboxRelay has already logged the failure with its class, SQLSTATE
            // and commit phase; this record is the pass-level counterpart, so the
            // two together say what failed and how far the pass had got.
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

        // A FULLY HANDLED PASS, and only now. Drained, batch-capped, budget-capped
        // and transport-rejected all count: each one means the process ran to
        // completion and knows what it did.
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
