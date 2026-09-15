<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * A separate OS process blocked in the gate's lock queue: a blocked statement halts its caller, and there is no async PDO.
 * The queued state is observed through pg_locks, never assumed after a sleep.
 */
final class AdvisoryExclusiveWaiter
{
    private const string CHILD_LOCK_TIMEOUT = '10s';

    private const float QUEUE_DEADLINE_SECONDS = 10.0;

    private const int POLL_INTERVAL_MICROSECONDS = 20_000;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(
        private readonly int $classId,
        private readonly int $objectId,
    ) {}

    public function start(): void
    {
        if ($this->process !== null) {
            throw new RuntimeException('The advisory waiter is already running.');
        }

        $config = config('database.connections.pgsql');

        if (! is_array($config)) {
            throw new RuntimeException('The pgsql connection configuration is unavailable.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['php'],
            $descriptors,
            $pipes,
            null,
            [
                'W_DSN' => sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    (string) ($config['host'] ?? ''),
                    (string) ($config['port'] ?? ''),
                    (string) ($config['database'] ?? ''),
                ),
                'W_USER' => (string) ($config['username'] ?? ''),
                'W_PASS' => (string) ($config['password'] ?? ''),
                'W_LOCK_TIMEOUT' => self::CHILD_LOCK_TIMEOUT,
                'W_CLASS' => (string) $this->classId,
                'W_OBJECT' => (string) $this->objectId,
            ],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the advisory waiter process.');
        }

        $this->process = $process;
        $this->pipes = $pipes;

        fwrite($pipes[0], $this->childScript());
        fclose($pipes[0]);
        unset($this->pipes[0]);

        $this->waitUntilQueued();
    }

    /** Safe to repeat and after a failed start(): a leaked child would hold the gate key for later tests. */
    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        $this->process = null;
    }

    private function waitUntilQueued(): void
    {
        $deadline = microtime(true) + self::QUEUE_DEADLINE_SECONDS;

        while (microtime(true) < $deadline) {
            $rows = ProjectionGateLocks::onKey($this->classId, $this->objectId);

            foreach ($rows as $row) {
                if ($row['mode'] === 'ExclusiveLock' && $row['granted'] === false) {
                    return;
                }

                if ($row['mode'] === 'ExclusiveLock' && $row['granted'] === true) {
                    $this->stop();

                    throw new RuntimeException(
                        'The advisory waiter was GRANTED the exclusive gate lock instead of queueing for it. '
                        .'The shared holder this probe depends on was not held, so nothing measured after '
                        .'this point would describe a pending exclusive request.'
                    );
                }
            }

            Sleep::usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        $this->stop();

        throw new RuntimeException(sprintf(
            'The advisory waiter never appeared in pg_locks as an ungranted exclusive request within %.1fs.',
            self::QUEUE_DEADLINE_SECONDS,
        ));
    }

    private function childScript(): string
    {
        return <<<'PHP'
        <?php

        $pdo = new PDO(
            (string) getenv('W_DSN'),
            (string) getenv('W_USER'),
            (string) getenv('W_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $pdo->exec(sprintf("set lock_timeout = '%s'", getenv('W_LOCK_TIMEOUT')));
        $pdo->beginTransaction();

        try {
            $pdo->query(sprintf(
                'select pg_advisory_xact_lock(%d, %d)',
                (int) getenv('W_CLASS'),
                (int) getenv('W_OBJECT'),
            ));

            fwrite(STDOUT, "acquired\n");
        } catch (Throwable $e) {
            fwrite(STDOUT, 'refused:'.$e->getCode()."\n");
        }

        $pdo->rollBack();
        PHP;
    }
}
