<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Payments\Application\Verification\ProjectionVerifier;
use Modules\Payments\Application\Verification\VerificationExample;

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
 * IT VERIFIES CANONICAL HISTORY FORWARD. `scanned` names the passes that ran, and
 * one key means one pass: an envelope, receipt or read row that NO stored event
 * points at cannot be seen from here, and the reverse scans that find those are
 * separate work. Nothing this command prints should be read as claiming otherwise.
 *
 * NOTHING SENSITIVE ON EITHER STREAM. The report carries categories, owned field
 * names and validated identifiers; no payload value, no idempotency key, no
 * exception text. An invalid configuration is refused by the container before the
 * audit's snapshot opens, so a bad bound is a startup failure rather than a
 * half-run report.
 */
final class VerifyPaymentProjectionsCommand extends Command
{
    protected $signature = 'payments:projections:verify';

    protected $description = 'Audit Payments canonical history against its derived state, read-only, as JSON';

    /** The output contract's version, bumped only by a breaking change to its shape. */
    public const int SCHEMA_VERSION = 1;

    public const string COMMAND = 'payments:projections:verify';

    /**
     * @param  Closure(): int  $clock  monotonic nanoseconds
     */
    public function __construct(
        private readonly ProjectionVerifier $verifier,
        private readonly Closure $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // hrtime(), not microtime(): a duration that survives an NTP step or a
        // daylight-saving jump. A wall clock going backwards mid-audit would
        // report a negative one.
        $startedAt = ($this->clock)();

        $report = $this->verifier->verify();

        $durationMs = (int) round((($this->clock)() - $startedAt) / 1_000_000);

        $this->line((string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'operation_id' => (string) Str::uuid7(),
            'command' => self::COMMAND,
            'outcome' => $report->outcome(),
            'reason' => $report->reason,
            'duration_ms' => $durationMs,
            'complete' => $report->complete,
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
