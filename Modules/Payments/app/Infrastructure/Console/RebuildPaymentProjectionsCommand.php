<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payments\Application\Rebuild\ProjectionRebuild;
use Modules\Payments\Application\Rebuild\RebuildOutcome;

/**
 * The operator-facing surface of the bounded batch rebuild: one JSON object,
 * one exit code - the same contract `payments:outbox:repair` established.
 *
 * A CONSOLE ADAPTER AND NOTHING ELSE. It mints the correlation id, measures the
 * wall clock, passes the confirmation through, renders the result and returns
 * the exit code the result derived. Every decision about what a rebuild means
 * belongs to RebuildOutcome.
 *
 * `--confirm-rebuild` IS AN ASSERTION, NOT A CONFIRMATION PROMPT, exactly for
 * the reason `--delivery-quiesced` is: it has to survive being written down in
 * a runbook and read back in a log. It does not decide recovery for the
 * operator - there is no separate `--recover` flag - because an authorized run
 * reads the durable control row and applies D7's rule itself; a flag here
 * would let an operator assert a state the row already knows.
 */
final class RebuildPaymentProjectionsCommand extends Command
{
    protected $signature = 'payments:projections:rebuild
        {--confirm-rebuild : Assert that this destructive rebuild of payment_details is authorized}';

    protected $description = 'Clear and replay the payment_details read model from canonical stored_events';

    /** The output contract's version, bumped only by a breaking change to its shape. */
    public const int SCHEMA_VERSION = 1;

    public const string COMMAND = 'payments:projections:rebuild';

    /**
     * @param  Closure(): int  $clock  monotonic nanoseconds
     */
    public function __construct(
        private readonly ProjectionRebuild $rebuild,
        private readonly Closure $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = ($this->clock)();
        $operationId = (string) Str::uuid7();

        $outcome = $this->rebuild->rebuild((bool) $this->option('confirm-rebuild'), $operationId);

        return $this->emitOutcome($outcome, $operationId, $startedAt);
    }

    private function emitOutcome(RebuildOutcome $outcome, string $operationId, int $startedAt): int
    {
        $durationMs = (int) round((($this->clock)() - $startedAt) / 1_000_000);

        $this->line((string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operationId,
            'command' => self::COMMAND,
            'outcome' => $outcome->outcome(),
            'reason' => $outcome->reason->value,
            'duration_ms' => $durationMs,
            'high_watermark' => $outcome->highWatermark,
            'events_replayed' => $outcome->eventsReplayed,
            'batches_committed' => $outcome->batchesCommitted,
            'guarded_recovery' => $outcome->guardedRecovery,
        ], JSON_UNESCAPED_SLASHES));

        return $outcome->exitCode();
    }
}
