<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Integration;

use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * A real second OS process, blocked in PostgreSQL's lock queue on the gate.
 *
 * WHY A PROCESS AND NOT A SECOND CONNECTION. The question this exists to answer
 * is what a shared try-lock does while an exclusive request is ALREADY WAITING.
 * A waiting request only exists while somebody is blocked inside
 * pg_advisory_xact_lock, and a blocked statement halts the caller. PHP here has
 * no async PDO and the pgsql extension (pg_send_query) is not installed in this
 * image, so the waiter has to be a separate process. It is spawned, observed
 * through pg_locks, and killed; nothing about it is a multi-process resilience
 * suite, which stays T12's.
 *
 * THE SCRIPT GOES IN ON STDIN, so there is no shell quoting anywhere and no
 * temporary file to clean up. `php` with no script argument executes stdin.
 *
 * THE BARRIER IS pg_locks, NOT A SLEEP. waitUntilQueued() polls for PostgreSQL's
 * own record of an UNGRANTED exclusive request and fails loudly on a deadline.
 * The poll interval is how often the observation is repeated, not how long the
 * test hopes is enough; without an observed ungranted row the probe refuses to
 * report anything.
 */
final class AdvisoryExclusiveWaiter
{
    /** How long the child is willing to sit in the lock queue. */
    private const string CHILD_LOCK_TIMEOUT = '10s';

    /** How long waitUntilQueued() waits for the child to reach the queue. */
    private const float QUEUE_DEADLINE_SECONDS = 10.0;

    /** How often the observer re-reads pg_locks while waiting. */
    private const int POLL_INTERVAL_MICROSECONDS = 20_000;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    public function __construct(
        private readonly int $classId,
        private readonly int $objectId,
    ) {}

    /**
     * Start the child and return once PostgreSQL reports it waiting.
     *
     * The child opens its OWN PDO connection. It inherits no handle from this
     * process, so nothing it does can disturb the default, observer or lock
     * connections the test is asserting on.
     */
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

    /**
     * Kill the child and release everything it held.
     *
     * Safe to call more than once and safe to call when start() failed, because
     * a teardown that can itself fail is a teardown that leaks a backend holding
     * the gate key into every test that follows.
     */
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

    /**
     * Block until the observer sees an ungranted exclusive request on the key.
     *
     * THE UNGRANTED ROW IS THE EVIDENCE. A granted row would mean the child won
     * the lock, which means the test's own shared holder was not held, and every
     * assertion afterwards would be measuring the wrong situation. Neither
     * outcome is allowed to pass silently.
     */
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

    /**
     * The child: connect, bound the wait, open a transaction, block on the gate.
     *
     * It writes nothing to any table and takes no row lock. Its entire purpose is
     * to occupy a position in PostgreSQL's lock queue for the duration of one
     * observation.
     */
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
