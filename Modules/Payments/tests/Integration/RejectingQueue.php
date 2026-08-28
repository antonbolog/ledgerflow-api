<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Contracts\Queue\Factory;
use RedisException;
use RuntimeException;
use Throwable;

/**
 * A transport that fails, with a failure of the caller's choosing.
 *
 * THE EXCEPTION CLASS IS THE ENTIRE POINT, and one fake with two named
 * constructors is what makes the catch boundary provable in BOTH directions:
 *
 *   redis()       -> RedisException  -> caught, backoff committed, pass continues
 *   programming() -> RuntimeException -> escapes, row rolled back, pass aborted
 *
 * A fake that could only throw RedisException would leave the second half
 * untested, and a relay that caught Throwable would pass the whole suite while
 * silently converting a serialisation bug or a lost connection into a durable
 * "transport rejected" backoff on a row that was never published. That is exactly
 * what happened: the mutation `catch (RedisException)` -> `catch (Throwable)` broke
 * no test until programming() existed.
 *
 * phpredis throws RedisException unwrapped — Laravel's PhpRedisConnection::command()
 * rethrows it as-is after an optional reconnect
 * (Redis/Connections/PhpRedisConnection.php:528-539) — so redis() is the shape the
 * relay really meets when Redis is unreachable or refuses a command.
 *
 * IT IS BOTH THE FACTORY AND THE QUEUE, deliberately. The relay only ever calls
 * connection()->push(), so a separate queue class would be two files describing one
 * behaviour. Throwing from connection() also covers the case that matters most:
 * when Redis is down, PhpRedisConnector::connect() fails while the client is being
 * built, before any command is sent.
 *
 * NOT A DEAD REDIS HOST. Pointing the connection at a closed port would also
 * produce a RedisException, but the test would then wait out a real connect timeout
 * and would prove the timeout as much as the classification.
 */
final readonly class RejectingQueue implements Factory
{
    private function __construct(private Throwable $failure) {}

    /** A transport rejection: retryable, backed off, never poison. */
    public static function redis(string $message = 'Connection refused'): self
    {
        return new self(new RedisException($message));
    }

    /**
     * Anything that is NOT a transport rejection — a serialisation fault, a
     * configuration error, a programming mistake inside the queue.
     *
     * The relay must let it escape. Recording it as a transport failure would put a
     * permanent defect on a retry schedule and report a healthy pass.
     */
    public static function programming(string $message = 'unserializable job payload'): self
    {
        return new self(new RuntimeException($message));
    }

    public function connection($name = null): never
    {
        throw $this->failure;
    }
}
