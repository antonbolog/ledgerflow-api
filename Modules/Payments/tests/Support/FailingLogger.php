<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

// Throws on the configured call: PSR-3 methods return void, so an unavailable sink can only throw.
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
