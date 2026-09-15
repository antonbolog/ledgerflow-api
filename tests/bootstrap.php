<?php

declare(strict_types=1);

// Runs before the autoloader, so a wrong target is refused before RefreshDatabase can migrate it.
// PHPUnit's <env force="true"> does not write $_SERVER, which Laravel reads first, so every source is validated and forced here.
const LEDGERFLOW_TEST_DATABASE = 'ledgerflow_test';

const LEDGERFLOW_TEST_ENVIRONMENT = 'testing';

// Mirrors the Makefile allowlist.
const LEDGERFLOW_ALLOWED_HOSTS = ['postgres'];

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

    if ($closing === null) {
        fwrite(STDERR, '  The suite truncates and re-migrates its database on every run,'.PHP_EOL);
        fwrite(STDERR, '  so it may only ever target '.LEDGERFLOW_TEST_DATABASE.'.'.PHP_EOL);
    } else {
        fwrite(STDERR, '  '.$closing.PHP_EOL);
    }

    fwrite(STDERR, PHP_EOL);

    exit(1);
};

$ledgerflowScalar = static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null;

// Never reads $_SERVER: it still carries the container environment being corrected below.
$ledgerflowIntent = static function (string $key) use ($ledgerflowScalar): string {
    $fromEnv = $ledgerflowScalar($_ENV[$key] ?? null);

    if ($fromEnv !== null) {
        return $fromEnv;
    }

    $value = getenv($key);

    return $value === false ? '' : $value;
};

/** @return array<string, string|null> */
$ledgerflowAllSources = static function (string $key) use ($ledgerflowScalar): array {
    $raw = getenv($key);

    return [
        '$_SERVER' => $ledgerflowScalar($_SERVER[$key] ?? null),
        '$_ENV' => $ledgerflowScalar($_ENV[$key] ?? null),
        'getenv' => $raw === false ? null : $raw,
    ];
};

// A compiled config cache ignores the environment, so forcing below could not take effect.
if (file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    $ledgerflowRefuse(
        'A cached configuration file is present.',
        'bootstrap/cache/config.php makes Laravel ignore the test environment. Run: php artisan config:clear',
    );
}

// Never echo a DSN: it can carry credentials, and this message reaches CI logs.
$ledgerflowRedactUrl = static function (string $url): string {
    $parts = parse_url($url);

    if ($parts === false) {
        return '(unparseable DSN, withheld)';
    }

    $host = is_string($parts['host'] ?? null) ? $parts['host'] : '(unknown host)';
    $path = is_string($parts['path'] ?? null) ? ltrim($parts['path'], '/') : '';

    return $path === '' ? $host : $host.'/'.$path;
};

// DB_URL overrides DB_HOST and DB_DATABASE, so it must be empty in every source.
foreach ($ledgerflowAllSources('DB_URL') as $ledgerflowSource => $ledgerflowValue) {
    if ($ledgerflowValue !== null && $ledgerflowValue !== '') {
        $ledgerflowRefuse(
            'DB_URL is set in '.$ledgerflowSource.'.',
            'DB_URL takes precedence over DB_HOST/DB_DATABASE and would redirect the suite to '
                .$ledgerflowRedactUrl($ledgerflowValue).' (credentials withheld).',
        );
    }
}

// Validate before forcing, so an edited or missing phpunit.xml entry is refused rather than overwritten.
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

// AppServiceProvider permits destructive commands only in the testing environment.
$ledgerflowEnvironment = $ledgerflowIntent('APP_ENV');

if ($ledgerflowEnvironment !== LEDGERFLOW_TEST_ENVIRONMENT) {
    $ledgerflowRefuse(
        'APP_ENV is not the testing environment.',
        'APP_ENV='.($ledgerflowEnvironment === '' ? '(unset)' : $ledgerflowEnvironment).', expected '.LEDGERFLOW_TEST_ENVIRONMENT.'.',
        'Destructive database commands are recognised only in the testing environment.',
    );
}

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
