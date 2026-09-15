<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Contracts\Queue\Factory;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * redis() is a transport rejection the relay must catch; programming() must escape.
 * Throwing from connection() also covers a Redis that fails while its client is being built.
 */
final readonly class RejectingQueue implements Factory
{
    private function __construct(private Throwable $failure) {}

    public static function redis(string $message = 'Connection refused'): self
    {
        return new self(new RedisException($message));
    }

    public static function programming(string $message = 'unserializable job payload'): self
    {
        return new self(new RuntimeException($message));
    }

    public function connection($name = null): never
    {
        throw $this->failure;
    }
}
