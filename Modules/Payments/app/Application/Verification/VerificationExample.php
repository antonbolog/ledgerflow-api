<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Verification;

// Examples are printed: carry validated identifiers and column names only, never payment values.
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
