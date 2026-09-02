<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

/**
 * One retained diagnostic, reduced to what is safe to print.
 *
 * IDENTIFIERS AND FIELD NAMES ONLY. `$fields` carries the names of the columns
 * that disagreed, never the values on either side: an example that printed the
 * actual and expected amount would put payment data on stdout and into whatever
 * captured it. What an operator needs is which event and which field, which is
 * enough to go and look.
 *
 * EVERY IDENTIFIER IS NULLABLE, AND WHICH ONES ARE PRESENT SAYS WHERE THE FINDING
 * CAME FROM. A canonical finding carries the global `stored_events.id` it was read
 * from - present even when the payload was too malformed to yield a durable event
 * id, so the worst-corrupted rows stay identifiable. A reverse finding has no
 * canonical row at all, and carries whichever derived key named it instead.
 *
 * ALL THREE ARE VALIDATED BEFORE THEY REACH HERE: a sequence number, or a
 * canonical UUID the schema or this audit has already refused anything malformed
 * for. Nothing caller-influenced is carried.
 */
final readonly class VerificationExample
{
    /** @param list<string> $fields */
    public function __construct(
        public VerificationFinding $finding,
        public ?int $storedEventId = null,
        public ?string $eventId = null,
        public ?string $paymentId = null,
        public array $fields = [],
    ) {}
}
