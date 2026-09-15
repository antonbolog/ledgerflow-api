<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payments\Application\Repair\OutboxRepair;
use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Application\Repair\RepairOutcome;
use Modules\Payments\Application\Repair\RepairReason;
use Override;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RepairPaymentOutboxCommand extends Command
{
    protected $signature = 'payments:outbox:repair
        {eventId : The durable Payments event id whose envelope should be repaired}
        {--delivery-quiesced : Assert that the scheduler, Horizon and every other delivery process are stopped}';

    protected $description = 'Reconstruct or correct one outbox envelope from canonical history, with an audit record';

    // Bump only for a breaking change to the JSON output shape.
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

    #[Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = ($this->clock)();

        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface) {
            return $this->emitOutcome(
                RepairOutcome::failed(
                    RepairReason::InvalidInvocation,
                    RepairMutationState::None,
                    null,
                    deliveryQuiesced: $input->hasParameterOption('--delivery-quiesced', true),
                ),
                (string) Str::uuid7(),
                $startedAt,
            );
        }
    }

    public function handle(): int
    {
        $startedAt = ($this->clock)();
        $operationId = (string) Str::uuid7();

        $outcome = $this->repair->repair(
            (string) $this->argument('eventId'),
            (bool) $this->option('delivery-quiesced'),
            $operationId,
        );

        return $this->emitOutcome($outcome, $operationId, $startedAt);
    }

    private function emitOutcome(RepairOutcome $outcome, string $operationId, int $startedAt): int
    {
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
