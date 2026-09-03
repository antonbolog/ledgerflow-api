<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A logger that works until the nth record, then does not.
 *
 * WHY A FAKE AND NOT A MOCK: the audit contract is about WHICH record fails and
 * what the command reports afterwards, so the interesting state is a call count.
 * AbstractLogger reduces the eight PSR-3 level methods to one `log()`, so the
 * counting happens in one place rather than eight.
 *
 * IT THROWS RATHER THAN RETURNING FALSE, because that is how a real sink fails:
 * PSR-3 methods return void, so an unavailable destination has no other way to
 * report itself.
 */
final class FailingLogger extends AbstractLogger
{
    private int $calls = 0;

    public function __construct(private readonly int $failOnCall) {}

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->calls++;

        if ($this->calls === $this->failOnCall) {
            throw new RuntimeException('the audit sink is unavailable');
        }
    }
}
