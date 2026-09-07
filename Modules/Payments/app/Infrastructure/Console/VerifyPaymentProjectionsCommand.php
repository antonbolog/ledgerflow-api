<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Payments\Application\Verification\ProjectionVerifier;
use Modules\Payments\Application\Verification\VerificationClassification;
use Modules\Payments\Application\Verification\VerificationExample;
use Modules\Payments\Application\Verification\VerificationReport;
use Override;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The operator-facing surface of the read-only audit: one JSON object, one exit code.
 *
 * A CONSOLE ADAPTER AND NOTHING ELSE. It measures the wall clock, mints the
 * correlation id, renders the report and returns the exit code the report
 * derived. Every decision about what the audit means belongs to
 * VerificationReport, so `payments:projections:verify | jq` and a future caller
 * of the port cannot disagree about whether a run passed.
 *
 * IT IS NOT SCHEDULED, and that is deliberate rather than pending. A recurring
 * audit needs somewhere for its answer to go; until deployment monitoring and an
 * alert destination exist, a scheduled run would produce an exit code nobody
 * reads. The runtime contract records this.
 *
 * `scanned` names the canonical and three reverse passes that started. A partial
 * run preserves those counts but never claims complete coverage.
 *
 * NOTHING SENSITIVE ON EITHER STREAM. The report carries categories, owned field
 * names and validated identifiers; no payload value, no idempotency key, no
 * exception text. The verifier resolves inside handle() so invalid configuration
 * becomes a structured failure before the audit's snapshot opens.
 */
final class VerifyPaymentProjectionsCommand extends Command
{
    protected $signature = 'payments:projections:verify';

    protected $description = 'Audit Payments canonical history against its derived state, read-only, as JSON';

    /** The output contract's version, bumped only by a breaking change to its shape. */
    public const int SCHEMA_VERSION = 1;

    public const string COMMAND = 'payments:projections:verify';

    /**
     * @param  Closure(): ProjectionVerifier  $resolveVerifier
     * @param  Closure(): int  $clock  monotonic nanoseconds
     */
    public function __construct(
        private readonly Closure $resolveVerifier,
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
            return $this->emitReport(self::invalidReport('invalid_invocation'), $startedAt);
        }
    }

    public function handle(): int
    {
        // hrtime(), not microtime(): a duration that survives an NTP step or a
        // daylight-saving jump. A wall clock going backwards mid-audit would
        // report a negative one.
        $startedAt = ($this->clock)();

        try {
            $verifier = ($this->resolveVerifier)();
        } catch (InvalidArgumentException) {
            $verifier = null;
        }

        $report = $verifier === null
            ? self::invalidReport('invalid_configuration')
            : $verifier->verify();

        return $this->emitReport($report, $startedAt);
    }

    private static function invalidReport(string $reason): VerificationReport
    {
        return VerificationReport::incomplete(
            reason: $reason,
            highWatermark: null,
            auditAt: null,
            scanned: [],
            classificationCounts: VerificationClassification::zeroed(),
            findingCounts: [],
            examples: [],
            examplesTruncated: false,
        );
    }

    private function emitReport(VerificationReport $report, int $startedAt): int
    {
        $durationMs = (int) round((($this->clock)() - $startedAt) / 1_000_000);

        $this->line((string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => (string) Str::uuid7(),
            'command' => self::COMMAND,
            'outcome' => $report->outcome(),
            'reason' => $report->reason,
            'duration_ms' => $durationMs,
            'complete' => $report->complete,
            'projection_control_state' => $report->projectionControlState,
            'high_watermark' => $report->highWatermark,
            'audit_at' => $report->auditAt,
            'scanned' => $report->scanned,
            'classification_counts' => $report->classificationCounts,
            'finding_counts' => $report->findingCounts,
            'examples' => array_map(self::renderExample(...), $report->examples),
            'examples_truncated' => $report->examplesTruncated,
        ], JSON_UNESCAPED_SLASHES));

        return $report->exitCode();
    }

    /**
     * WHICH IDENTIFIERS ARE PRESENT SAYS WHERE THE FINDING CAME FROM. A canonical
     * finding carries the global stored-event id it was read from; a reverse
     * finding has no canonical row and carries whichever derived key named it. All
     * three keys are always rendered, so a consumer never has to tell an absent key
     * from a null one.
     *
     * @return array{finding: string, stored_event_id: int|null, event_id: string|null, payment_id: string|null, fields: list<string>}
     */
    private static function renderExample(VerificationExample $example): array
    {
        return [
            'finding' => $example->finding->value,
            'stored_event_id' => $example->storedEventId,
            'event_id' => $example->eventId,
            'payment_id' => $example->paymentId,
            'fields' => $example->fields,
        ];
    }
}
