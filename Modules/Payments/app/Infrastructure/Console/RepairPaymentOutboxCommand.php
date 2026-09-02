<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payments\Application\Repair\OutboxRepair;

/**
 * The operator-facing surface of targeted repair: one JSON object, one exit code.
 *
 * A CONSOLE ADAPTER AND NOTHING ELSE. It mints the correlation id, measures the
 * wall clock, passes the acknowledgement through, renders the result and returns
 * the exit code the result derived. Every decision about what a repair means
 * belongs to RepairOutcome, so a future caller of the port and
 * `payments:outbox:repair | jq` cannot disagree about whether one succeeded.
 *
 * THE ACKNOWLEDGEMENT IS AN OPTION, NOT A CONFIRMATION PROMPT. It has to survive
 * being written down in a runbook and read back in a log, which a keystroke does
 * not. It is the operator's assertion that every delivery and failure-recorder
 * process has stopped - including standalone consumers, manual commands and
 * processes on other hosts - and it is recorded as an assertion, never as
 * machine-verified proof. This command starts and stops nothing itself.
 *
 * IT PRINTS NO SUPPLIED IDENTIFIER IT HAS NOT VALIDATED. A malformed event id is
 * refused with a null `event_id`, because echoing operator input into stdout and
 * the audit is how unvalidated data ends up in whatever collects them.
 */
final class RepairPaymentOutboxCommand extends Command
{
    protected $signature = 'payments:outbox:repair
        {eventId : The durable Payments event id whose envelope should be repaired}
        {--delivery-quiesced : Assert that the scheduler, Horizon and every other delivery process are stopped}';

    protected $description = 'Reconstruct or correct one outbox envelope from canonical history, with an audit record';

    /** The output contract's version, bumped only by a breaking change to its shape. */
    public const int SCHEMA_VERSION = 1;

    public const string COMMAND = 'payments:outbox:repair';

    /**
     * @param  Closure(): int  $clock  monotonic nanoseconds
     */
    public function __construct(
        private readonly OutboxRepair $repair,
        private readonly Closure $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // hrtime(), not microtime(): a duration that survives an NTP step or a
        // daylight-saving jump, for the reason the relay command records.
        $startedAt = ($this->clock)();
        $operationId = (string) Str::uuid7();

        $outcome = $this->repair->repair(
            (string) $this->argument('eventId'),
            (bool) $this->option('delivery-quiesced'),
            $operationId,
        );

        $durationMs = (int) round((($this->clock)() - $startedAt) / 1_000_000);

        $this->line((string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => $operationId,
            'command' => self::COMMAND,
            'outcome' => $outcome->outcome(),
            'reason' => $outcome->reason->value,
            'duration_ms' => $durationMs,
            'event_id' => $outcome->eventId,
            'delivery_quiesced' => $outcome->deliveryQuiesced,
            'mutation_state' => $outcome->mutationState->value,
            'changed_fields' => $outcome->changedFields,
            'history_reinitialized' => $outcome->historyReinitialized,
        ], JSON_UNESCAPED_SLASHES));

        return $outcome->exitCode();
    }
}
