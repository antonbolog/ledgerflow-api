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
 * @see docs/testing/payments-resilience.md — SQLite is not a substitute for
 *      PostgreSQL concurrency and advisory-lock behaviour.
 */
const LEDGERFLOW_TEST_DATABASE = 'ledgerflow_test';

/** Hosts the suite may reach. Mirrors the Makefile allowlist. */
const LEDGERFLOW_ALLOWED_HOSTS = ['postgres', '127.0.0.1', 'localhost', '::1'];

$ledgerflowRefuse = static function (string $reason, string $detail): never {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, 'REFUSING TO RUN THE TEST SUITE'.PHP_EOL);
    fwrite(STDERR, '  '.$reason.PHP_EOL);
    fwrite(STDERR, '  '.$detail.PHP_EOL);
    fwrite(STDERR, '  The suite truncates and re-migrates its database on every run,'.PHP_EOL);
    fwrite(STDERR, '  so it may only ever target '.LEDGERFLOW_TEST_DATABASE.'.'.PHP_EOL);
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

// Make the validated intent authoritative across every source Laravel consults,
// so $_SERVER can no longer shadow it.
$ledgerflowForce = array_merge($ledgerflowExpected, [
    'DB_HOST' => $ledgerflowHost,
    'DB_URL' => '',
]);

foreach ($ledgerflowForce as $ledgerflowKey => $ledgerflowValue) {
    $_SERVER[$ledgerflowKey] = $ledgerflowValue;
    $_ENV[$ledgerflowKey] = $ledgerflowValue;
    putenv($ledgerflowKey.'='.$ledgerflowValue);
}

// Prove it: every source must now agree, or the forcing above did not take.
foreach (['DB_CONNECTION', 'DB_HOST', 'DB_DATABASE'] as $ledgerflowKey) {
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
