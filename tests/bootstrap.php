<?php

declare(strict_types=1);

/**
 * Layer 1 of the test-database guard.
 *
 * This file is the PHPUnit bootstrap, so it runs before the autoloader, before
 * PHPUnit builds a single object, and — decisively — before Laravel's
 * RefreshDatabase trait can migrate anything.
 *
 * That timing is the whole point. RefreshDatabase migrates during setUp(), so
 * an assertion inside a test body reports the damage after it has happened. By
 * then a wrong connection has already dropped every table in whatever database
 * it reached. A guard that runs here can only ever prevent it.
 *
 * WHY THIS FILE HAS TO FORCE $_SERVER
 * -----------------------------------
 * PHPUnit's <env force="true"> writes putenv() and $_ENV. It does NOT write
 * $_SERVER. Laravel resolves env() through Dotenv, whose default adapter order
 * puts $_SERVER FIRST. Docker Compose's env_file injects real process
 * environment variables, which PHP mirrors into $_SERVER.
 *
 * So with the container holding DB_DATABASE=ledgerflow and phpunit.xml forcing
 * ledgerflow_test, the sources disagree and Laravel silently picks the
 * container's value. Measured, not assumed: the suite ran against the
 * development database until this file started forcing $_SERVER too.
 *
 * The remaining defeat mechanisms are covered as well:
 *
 *   - DB_URL takes precedence over DB_HOST/DB_DATABASE in Laravel's database
 *     config, so any source setting it would redirect the suite.
 *   - A cached bootstrap/cache/config.php makes Laravel ignore the environment
 *     entirely and use whatever was compiled into the cache.
 *
 * THE SAME TRAP, A SECOND BLAST RADIUS
 * ------------------------------------
 * The database keys were fixed here first because they were the loudest. The
 * queue, cache and session keys have exactly the same defeat mechanism and were
 * left declared-but-not-enforced until T4 measured it: config('queue.default')
 * resolved to 'redis', not the declared 'sync', so the suite's DEFAULT queue was
 * a real Redis queue on logical database 0 — the database a developer's stack
 * uses. An unnamed dispatch would have written into it, and a cache or session
 * write would have shared that keyspace too.
 *
 * A suite that reaches Redis explicitly and by name is fine. A suite that
 * reaches it through a framework default nobody chose is not.
 *
 * @see docs/testing/payments-resilience.md — SQLite is not a substitute for
 *      PostgreSQL concurrency and advisory-lock behaviour.
 */
const LEDGERFLOW_TEST_DATABASE = 'ledgerflow_test';

const LEDGERFLOW_TEST_ENVIRONMENT = 'testing';

/** Hosts the suite may reach. Mirrors the Makefile allowlist. */
const LEDGERFLOW_ALLOWED_HOSTS = ['postgres'];

/**
 * Isolation defaults phpunit.xml declares, which must survive $_SERVER.
 *
 * These do not decide WHICH shared service the suite reaches; they keep it from
 * reaching one at all through a default. Anything that genuinely needs Redis
 * names its connection explicitly.
 */
const LEDGERFLOW_ISOLATION = [
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];

$ledgerflowRefuse = static function (string $reason, string $detail, ?string $closing = null): never {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'REFUSING TO RUN THE TEST SUITE'.PHP_EOL);
    fwrite(STDERR, '  '.$reason.PHP_EOL);
    fwrite(STDERR, '  '.$detail.PHP_EOL);

    // The database closing stays byte-identical to what it always printed; the
    // isolation checks pass their own, because "it may only ever target
    // ledgerflow_test" says nothing useful about a queue driver.
    if ($closing === null) {
        fwrite(STDERR, '  The suite truncates and re-migrates its database on every run,'.PHP_EOL);
        fwrite(STDERR, '  so it may only ever target '.LEDGERFLOW_TEST_DATABASE.'.'.PHP_EOL);
    } else {
        fwrite(STDERR, '  '.$closing.PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL);

    exit(1);
};

/**
 * The declared intent, which phpunit.xml owns. PHPUnit writes $_ENV and
 * putenv(), so both reflect the configured value; $_SERVER is deliberately not
 * consulted here because it still carries the container's own environment and
 * is the thing being corrected below.
 */
$ledgerflowScalar = static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null;

$ledgerflowIntent = static function (string $key) use ($ledgerflowScalar): string {
    $fromEnv = $ledgerflowScalar($_ENV[$key] ?? null);

    if ($fromEnv !== null) {
        return $fromEnv;
    }

    $value = getenv($key);

    return $value === false ? '' : $value;
};

/**
 * Every source, used to prove nothing is left disagreeing.
 *
 * @return array<string, string|null>
 */
$ledgerflowAllSources = static function (string $key) use ($ledgerflowScalar): array {
    $raw = getenv($key);

    return [
        '$_SERVER' => $ledgerflowScalar($_SERVER[$key] ?? null),
        '$_ENV' => $ledgerflowScalar($_ENV[$key] ?? null),
        'getenv' => $raw === false ? null : $raw,
    ];
};

// A compiled config cache overrides the environment wholesale, so no amount of
// env forcing below would matter.
if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    $ledgerflowRefuse(
        'A cached configuration file is present.',
        'bootstrap/cache/config.php makes Laravel ignore the test environment. Run: php artisan config:clear',
    );
}

/**
 * A rejected DB_URL is never echoed back. A DSN routinely carries a username,
 * a password and query parameters, and this message goes to stderr, CI logs and
 * screen shares. Only the host and database name are reported, and only to make
 * the refusal actionable.
 */
$ledgerflowRedactUrl = static function (string $url): string {
    $parts = parse_url($url);

    if ($parts === false) {
        return '(unparseable DSN, withheld)';
    }

    $host = is_string($parts['host'] ?? null) ? $parts['host'] : '(unknown host)';
    $path = is_string($parts['path'] ?? null) ? ltrim($parts['path'], '/') : '';

    return $path === '' ? $host : $host.'/'.$path;
};

// DB_URL wins over DB_HOST/DB_DATABASE wherever it is set, so it must be empty
// in every source, not merely in the one phpunit.xml controls.
foreach ($ledgerflowAllSources('DB_URL') as $ledgerflowSource => $ledgerflowValue) {
    if ($ledgerflowValue !== null && $ledgerflowValue !== '') {
        $ledgerflowRefuse(
            'DB_URL is set in '.$ledgerflowSource.'.',
            'DB_URL takes precedence over DB_HOST/DB_DATABASE and would redirect the suite to '
                .$ledgerflowRedactUrl($ledgerflowValue).' (credentials withheld).',
        );
    }
}

// Validate the declared intent BEFORE making it authoritative. Editing
// phpunit.xml to name the development database, or removing the entry so the
// container's value shows through, is refused here.
$ledgerflowExpected = [
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => LEDGERFLOW_TEST_DATABASE,
];

foreach ($ledgerflowExpected as $ledgerflowKey => $ledgerflowWanted) {
    $ledgerflowActual = $ledgerflowIntent($ledgerflowKey);

    if ($ledgerflowActual !== $ledgerflowWanted) {
        $ledgerflowRefuse(
            $ledgerflowKey.' is not the allowlisted test value.',
            $ledgerflowKey.'='.($ledgerflowActual === '' ? '(unset)' : $ledgerflowActual).", expected {$ledgerflowWanted}.",
        );
    }
}

$ledgerflowHost = $ledgerflowIntent('DB_HOST');

if (! in_array($ledgerflowHost, LEDGERFLOW_ALLOWED_HOSTS, true)) {
    $ledgerflowRefuse(
        'The test host is not a local project host.',
        'DB_HOST='.($ledgerflowHost === '' ? '(unset)' : $ledgerflowHost).', allowed: '.implode(', ', LEDGERFLOW_ALLOWED_HOSTS),
    );
}

// Compose's APP_ENV=local reaches $_SERVER exactly like the database keys do, and
// AppServiceProvider permits destructive commands only in the testing environment.
$ledgerflowEnvironment = $ledgerflowIntent('APP_ENV');

if ($ledgerflowEnvironment !== LEDGERFLOW_TEST_ENVIRONMENT) {
    $ledgerflowRefuse(
        'APP_ENV is not the testing environment.',
        'APP_ENV='.($ledgerflowEnvironment === '' ? '(unset)' : $ledgerflowEnvironment).', expected '.LEDGERFLOW_TEST_ENVIRONMENT.'.',
        'Destructive database commands are recognised only in the testing environment.',
    );
}

// Validate the isolation intent the same way, and for the same reason: if
// someone edits phpunit.xml to point the suite's default queue back at Redis,
// that is a decision to make deliberately, not one to have silently restored by
// this file forcing a value nobody declared.
foreach (LEDGERFLOW_ISOLATION as $ledgerflowKey => $ledgerflowWanted) {
    $ledgerflowActual = $ledgerflowIntent($ledgerflowKey);

    if ($ledgerflowActual !== $ledgerflowWanted) {
        $ledgerflowRefuse(
            $ledgerflowKey.' is not the declared test isolation value.',
            $ledgerflowKey.'='.($ledgerflowActual === '' ? '(unset)' : $ledgerflowActual).", expected {$ledgerflowWanted}.",
            'The suite must not reach shared queue, cache or session infrastructure through a default.',
        );
    }
}

// Make the validated intent authoritative across every source Laravel consults,
// so $_SERVER can no longer shadow it.
$ledgerflowForce = array_merge($ledgerflowExpected, LEDGERFLOW_ISOLATION, [
    'APP_ENV' => LEDGERFLOW_TEST_ENVIRONMENT,
    'DB_HOST' => $ledgerflowHost,
    'DB_URL' => '',
]);

foreach ($ledgerflowForce as $ledgerflowKey => $ledgerflowValue) {
    $_SERVER[$ledgerflowKey] = $ledgerflowValue;
    $_ENV[$ledgerflowKey] = $ledgerflowValue;
    putenv($ledgerflowKey.'='.$ledgerflowValue);
}

// Prove it: every source must now agree, or the forcing above did not take.
$ledgerflowProven = array_merge(
    ['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE'],
    array_keys(LEDGERFLOW_ISOLATION),
);

foreach ($ledgerflowProven as $ledgerflowKey) {
    foreach ($ledgerflowAllSources($ledgerflowKey) as $ledgerflowSource => $ledgerflowValue) {
        if ($ledgerflowValue !== $ledgerflowForce[$ledgerflowKey]) {
            $ledgerflowRefuse(
                'Environment sources still disagree after forcing.',
                $ledgerflowKey.' in '.$ledgerflowSource.' is '.var_export($ledgerflowValue, true).', expected '.$ledgerflowForce[$ledgerflowKey].'.',
            );
        }
    }
}

require __DIR__.'/../vendor/autoload.php';
