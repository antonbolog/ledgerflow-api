<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payments\Application\Rebuild\ProjectionRebuild;
use Modules\Payments\Application\Rebuild\RebuildOutcome;

final class RebuildPaymentProjectionsCommand extends Command
{
    protected $signature = 'payments:projections:rebuild
        {--confirm-rebuild : Assert that this destructive rebuild of payment_details is authorized}';

    protected $description = 'Clear and replay the payment_details read model from canonical stored_events';

    // Bump only for a breaking change to the JSON output shape.
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
