<?php

declare(strict_types=1);

use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\DB;
use Modules\Payments\Domain\Payment\Events\PaymentInitiated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;
use Spatie\EventSourcing\Attributes\EventVersion;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;
use Symfony\Component\Finder\Finder;

// Token scans, not arch(): arch() errors on a namespace with no classes. Dynamic class strings escape these rules.
const LEDGERFLOW_MODULES_PATH = __DIR__.'/../../Modules';

/**
 * Exact names, never prefixes; see docs/adr/0002-payments-domain-event-sourcing-allowlist.md.
 *
 * @var array<string, list<string>>
 */
const LEDGERFLOW_DOMAIN_ALLOWLIST = [
    'Payments' => [
        AggregateRoot::class,
        ShouldBeStored::class,
    ],
];

function ledgerflowModulePath(string $module): string
{
    return LEDGERFLOW_MODULES_PATH.'/'.$module;
}

/** @return list<string> */
function ledgerflowModules(): array
{
    $modules = [];

    foreach (Finder::create()->directories()->depth(0)->in(LEDGERFLOW_MODULES_PATH) as $directory) {
        $modules[] = $directory->getFilename();
    }

    sort($modules);

    return $modules;
}

/** @return array<string, list<array{0: int, 1: string, 2: int}|string>> */
function ledgerflowTokens(string $directory): array
{
    static $cache = [];

    if (isset($cache[$directory])) {
        return $cache[$directory];
    }

    if (! is_dir($directory)) {
        return $cache[$directory] = [];
    }

    $files = [];

    $finder = Finder::create()->files()->in($directory)->name('*.php')
        ->exclude(['tests', 'vendor', 'node_modules']);

    foreach ($finder as $file) {
        $files[$file->getRelativePathname()] = token_get_all((string) $file->getContents());
    }

    return $cache[$directory] = $files;
}

/** @return array<string, list<string>> */
function ledgerflowReferences(string $directory): array
{
    $references = [];

    foreach (ledgerflowTokens($directory) as $relative => $tokens) {
        $names = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            if (ledgerflowPreviousToken($tokens, $index) === T_NAMESPACE) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            // Single-segment names are global, so `\RuntimeException` and `use RuntimeException;` stay equivalent.
            if (! str_contains($name, '\\')) {
                continue;
            }

            $names[] = $name;
        }

        $references[$relative] = array_values(array_unique($names));
    }

    return $references;
}

/** @return array<string, list<string>> */
function ledgerflowHelperCalls(string $directory): array
{
    $helpers = [
        'abort', 'app', 'auth', 'cache', 'collect', 'config', 'dispatch', 'env', 'event',
        'logger', 'now', 'report', 'request', 'resolve', 'response', 'route', 'session',
        'today', 'trans', 'url', 'validator', 'view',
    ];

    $calls = [];

    foreach (ledgerflowTokens($directory) as $relative => $tokens) {
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array(strtolower($token[1]), $helpers, true)) {
                continue;
            }

            if (ledgerflowNextSymbol($tokens, $index) !== '(') {
                continue;
            }

            if (in_array(
                ledgerflowPreviousToken($tokens, $index),
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION],
                true,
            )) {
                continue;
            }

            $found[] = $token[1];
        }

        $calls[$relative] = array_values(array_unique($found));
    }

    return $calls;
}

/**
 * @param  array<string, list<string>>  $references
 * @return list<string>
 */
function ledgerflowDomainViolations(string $module, array $references): array
{
    $ownNamespace = "Modules\\{$module}\\Domain\\";
    $allowed = LEDGERFLOW_DOMAIN_ALLOWLIST[$module] ?? [];
    $violations = [];

    foreach ($references as $file => $names) {
        foreach ($names as $name) {
            if (str_starts_with($name, $ownNamespace) || in_array($name, $allowed, true)) {
                continue;
            }

            $violations[] = "Modules/{$module}/app/Domain/{$file} references {$name}.";
        }
    }

    return $violations;
}

/** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
function ledgerflowPreviousToken(array $tokens, int $index): int|string|null
{
    for ($i = $index - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($tokens[$i]) ? $tokens[$i][0] : $tokens[$i];
    }

    return null;
}

/** @param  list<array{0: int, 1: string, 2: int}|string>  $tokens */
function ledgerflowFirstStringArgument(array $tokens, int $index): ?string
{
    $opened = false;

    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (! $opened) {
            if ($token !== '(') {
                return null;
            }

            $opened = true;

            continue;
        }

        return is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            ? trim($token[1], '\'"')
            : null;
    }

    return null;
}

/** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
function ledgerflowCallReceiver(array $tokens, int $index): ?string
{
    $afterOperator = false;
    $depth = 0;

    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if ($depth > 0) {
            $depth += $token === ')' ? 1 : ($token === '(' ? -1 : 0);

            continue;
        }

        if (! $afterOperator) {
            if (! is_array($token)
                || ! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            ) {
                return '';
            }

            $afterOperator = true;

            continue;
        }

        if ($token === ')') {
            $depth = 1;

            continue;
        }

        if (! is_array($token)) {
            return null;
        }

        $name = ltrim($token[1], '$');
        $separator = strrpos($name, '\\');

        return strtolower($separator === false ? $name : substr($name, $separator + 1));
    }

    return null;
}

/** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
function ledgerflowNextSymbol(array $tokens, int $index): ?string
{
    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return is_array($tokens[$i]) ? null : $tokens[$i];
    }

    return null;
}

dataset('modules', ledgerflowModules(...));

it('lets Domain reference nothing outside its own namespace and the ADR 0002 allowlist', function (string $module): void {
    $violations = ledgerflowDomainViolations(
        $module,
        ledgerflowReferences(ledgerflowModulePath($module).'/app/Domain'),
    );

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

it('grants the ADR 0002 exception to exactly two classes in exactly one module', function (): void {
    expect(LEDGERFLOW_DOMAIN_ALLOWLIST)->toBe([
        'Payments' => [
            AggregateRoot::class,
            ShouldBeStored::class,
        ],
    ]);
});

it('admits exactly the two ADR 0002 base types into Payments Domain', function (): void {
    expect(ledgerflowDomainViolations('Payments', ['PaymentAggregate.php' => [
        AggregateRoot::class,
        ShouldBeStored::class,
        PaymentInitiated::class,
    ]]))->toBeEmpty();
});

it('refuses every other event-sourcing type in Payments Domain', function (string $forbidden): void {
    expect(ledgerflowDomainViolations('Payments', ['Fake.php' => [$forbidden]]))->not->toBeEmpty();
})->with([
    Projector::class,
    EventVersion::class,
    DB::class,
]);

it('grants the allowlist to Payments only', function (): void {
    expect(ledgerflowDomainViolations('Merchant', ['Fake.php' => [
        AggregateRoot::class,
    ]]))->not->toBeEmpty();
});

it('keeps Domain free of framework global helpers', function (string $module): void {
    $violations = [];

    foreach (ledgerflowHelperCalls(ledgerflowModulePath($module).'/app/Domain') as $file => $calls) {
        if ($calls !== []) {
            $violations[] = "Modules/{$module}/app/Domain/{$file} calls ".implode('(), ', $calls).'().';
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

it('lets Application reference its own module and other modules Application contracts', function (string $module): void {
    $allowed = [
        "Modules\\{$module}\\Domain\\",
        "Modules\\{$module}\\Application\\",
    ];

    foreach (ledgerflowModules() as $other) {
        if ($other !== $module) {
            $allowed[] = "Modules\\{$other}\\Application\\";
        }
    }

    $violations = [];

    foreach (ledgerflowReferences(ledgerflowModulePath($module).'/app/Application') as $file => $names) {
        foreach ($names as $name) {
            $matches = array_filter($allowed, fn (string $prefix): bool => str_starts_with($name, $prefix));

            if ($matches === []) {
                $violations[] = "Modules/{$module}/app/Application/{$file} references {$name}.";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

it('reaches another module only through its Application namespace', function (string $module): void {
    $violations = [];

    foreach (ledgerflowModules() as $other) {
        if ($other === $module) {
            continue;
        }

        foreach (ledgerflowReferences(ledgerflowModulePath($module)) as $file => $names) {
            foreach ($names as $name) {
                if (! str_starts_with($name, "Modules\\{$other}\\")) {
                    continue;
                }

                if (! str_starts_with($name, "Modules\\{$other}\\Application\\")) {
                    $violations[] = "Modules/{$module}/{$file} references {$name}; only the Application contract is permitted.";
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

it('keeps Merchant unaware of Payments entirely', function (): void {
    $violations = [];

    foreach (ledgerflowReferences(ledgerflowModulePath('Merchant')) as $file => $names) {
        foreach ($names as $name) {
            if (str_starts_with($name, 'Modules\\Payments\\')) {
                $violations[] = "Modules/Merchant/{$file} references {$name}.";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

it('keeps app/ out of module internals', function (): void {
    $violations = [];

    foreach (ledgerflowReferences(__DIR__.'/../../app') as $file => $names) {
        foreach ($names as $name) {
            if (preg_match('/^Modules\\\\\w+\\\\(Domain|Infrastructure)\\\\/', $name) === 1) {
                $violations[] = "app/{$file} references {$name}; the skeleton must not reach into a module's internals.";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

it('never delegates concurrency classification to laravel', function (): void {
    // Laravel's detector does not recognise 55P03 (ADR 0003). Exact names keep ConnectionInterface and DeadlockException allowed.
    $forbidden = [
        DetectsConcurrencyErrors::class,
        ConcurrencyErrorDetector::class,
    ];

    $violations = [];

    foreach (ledgerflowModules() as $module) {
        foreach (ledgerflowReferences(ledgerflowModulePath($module)) as $file => $names) {
            foreach ($names as $name) {
                if (in_array($name, $forbidden, true)) {
                    $violations[] = "Modules/{$module}/{$file} references {$name}; "
                        .'concurrency classification is owned by RetryingAtomicTransaction::sqlStateOf().';
                }
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
});

it('gives every module all four layers', function (string $module): void {
    // A missing layer directory would make its boundary rules pass vacuously.
    $missing = [];

    foreach (['Domain', 'Application', 'Infrastructure', 'Http'] as $layer) {
        if (! is_dir(ledgerflowModulePath($module).'/app/'.$layer)) {
            $missing[] = $layer;
        }
    }

    expect($missing)->toBeEmpty(
        "Modules/{$module}/app is missing: ".implode(', ', $missing)
    );
})->with('modules');

it('organises module application code into the permitted directories only', function (string $module): void {
    $permitted = ['Domain', 'Application', 'Infrastructure', 'Http', 'Providers'];
    $appPath = ledgerflowModulePath($module).'/app';

    foreach (Finder::create()->directories()->depth(0)->in($appPath) as $directory) {
        expect($directory->getFilename())->toBeIn($permitted);
    }

    $strays = [];

    foreach (Finder::create()->files()->depth(0)->in($appPath) as $file) {
        $strays[] = $file->getFilename();
    }

    expect($strays)->toBeEmpty(
        "Modules/{$module}/app holds ungoverned files: ".implode(', ', $strays)
    );
})->with('modules');

it('carries no frontend, module config or example routing', function (string $module): void {
    foreach (['resources', 'lang', 'config', 'routes', 'package.json', 'vite.config.js'] as $path) {
        expect(file_exists(ledgerflowModulePath($module).'/'.$path))->toBeFalse(
            "Modules/{$module}/{$path} is boilerplate this project does not keep."
        );
    }
})->with('modules');

// Any after-commit opt-in would defer relay publication past COMMIT (ADR 0004); ->afterCommit() leaves no runtime trace.
const LEDGERFLOW_RELAY_SOURCE = __DIR__.'/../../Modules/Payments/app/Infrastructure/Relay';

const LEDGERFLOW_DISPATCH_ADJACENT_SOURCE = [
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Relay',
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Projection',
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Console',
];

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function ledgerflowAfterCommitOptIns(array $tokens): array
{
    $found = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        [$type, $text] = [$token[0], $token[1]];

        if (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)
            && str_ends_with(ltrim($text, '\\'), 'ShouldQueueAfterCommit')) {
            $found[] = 'implements ShouldQueueAfterCommit';

            continue;
        }

        if ($type === T_VARIABLE && strtolower($text) === '$aftercommit') {
            $found[] = '$afterCommit property';

            continue;
        }

        // PHP method names are case-insensitive.
        if ($type === T_STRING
            && strtolower($text) === 'aftercommit'
            && ledgerflowNextSymbol($tokens, $index) === '('
            && in_array(
                ledgerflowPreviousToken($tokens, $index),
                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                true,
            )) {
            $found[] = '->afterCommit() call';
        }
    }

    return array_values(array_unique($found));
}

it('keeps every Payments dispatch-adjacent source file clear of after-commit opt-ins', function (): void {
    $scanned = [];
    $violations = [];

    foreach (LEDGERFLOW_DISPATCH_ADJACENT_SOURCE as $directory) {
        expect(is_dir($directory))->toBeTrue(
            "The scanned directory {$directory} has moved; this rule now scans less than it claims."
        );

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $scanned[] = $file->getFilename();

            foreach (ledgerflowAfterCommitOptIns(token_get_all((string) $file->getContents())) as $violation) {
                $violations[] = $file->getRelativePathname().': '.$violation;
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));

    // An emptied scan would pass, so known files and a minimum count guard the scan itself.
    expect($scanned)->toContain('ProjectPaymentEvent.php')
        ->toContain('PaymentProjectionConsumer.php');

    $expected = array_sum(array_map(
        fn (string $directory): int => count(glob($directory.'/*.php') ?: []),
        LEDGERFLOW_DISPATCH_ADJACENT_SOURCE,
    ));

    expect(count($scanned))->toBeGreaterThanOrEqual($expected);
});

it('detects each after-commit spelling in fabricated source', function (string $source, string $expected): void {
    expect(ledgerflowAfterCommitOptIns(token_get_all($source)))->toBe([$expected]);
})->with([
    ['<?php class M implements \Illuminate\Contracts\Queue\ShouldQueueAfterCommit {}', 'implements ShouldQueueAfterCommit'],
    ['<?php final class M { public bool $afterCommit = true; }', '$afterCommit property'],
    ['<?php dispatch(new M())->afterCommit();', '->afterCommit() call'],
    ['<?php dispatch(new M())->AfterCommit();', '->afterCommit() call'],
]);

it('does not mistake a comment or an unrelated name for an opt-in', function (): void {
    expect(ledgerflowAfterCommitOptIns(token_get_all(
        '<?php /** never use $afterCommit, ->afterCommit() or ShouldQueueAfterCommit */ final class M {}'
    )))->toBeEmpty();

    expect(ledgerflowAfterCommitOptIns(token_get_all(
        '<?php final class M { public function afterCommitHook(): void {} }'
    )))->toBeEmpty();
});

/**
 * Scoped to module app/ code: tests must exercise the same path production takes. The seeder's environment guard is out of scope.
 * runningInConsole() is absent because it separates console from HTTP, not test from production.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function ledgerflowEnvironmentBranches(array $tokens): array
{
    $methods = ['environment', 'runningunittests', 'isproduction', 'islocal'];
    $functions = ['env', 'getenv'];

    // Receiver => call => argument: both are required, since `$collection->get('app.env')` is not a config read.
    $keyed = [
        '' => ['config' => 'app.env', 'app' => 'env', 'resolve' => 'env'],
        'app' => ['make' => 'env', 'get' => 'env'],
        'config' => ['get' => 'app.env'],
    ];

    $found = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        [$type, $text] = [$token[0], $token[1]];

        if ($type === T_VARIABLE && in_array($text, ['$_ENV', '$_SERVER'], true)) {
            $found[] = $text;

            continue;
        }

        if (in_array($type, [T_STRING, T_CONSTANT_ENCAPSED_STRING], true) && str_contains($text, 'PHPUNIT')) {
            $found[] = 'PHPUNIT constant';

            continue;
        }

        if ($type !== T_STRING || ledgerflowNextSymbol($tokens, $index) !== '(') {
            continue;
        }

        $previous = ledgerflowPreviousToken($tokens, $index);
        $lowered = strtolower($text);

        // '' is a plain function call, null an unattributable or declared name.
        $receiver = $previous === T_FUNCTION ? null : ledgerflowCallReceiver($tokens, $index);
        $key = $keyed[$receiver ?? '#'][$lowered] ?? null;

        if ($key !== null && ledgerflowFirstStringArgument($tokens, $index) === $key) {
            $found[] = sprintf("%s('%s') call", $text, $key);

            continue;
        }

        if ($receiver === 'app'
            ? in_array($lowered, $methods, true)
            : $receiver === '' && in_array($lowered, $functions, true)
        ) {
            $found[] = $text.'() call';
        }
    }

    return array_values(array_unique($found));
}

it('keeps production module code free of environment-conditioned branches', function (string $module): void {
    $scanned = [];
    $violations = [];

    foreach (ledgerflowTokens(ledgerflowModulePath($module).'/app') as $file => $tokens) {
        $scanned[] = $file;

        foreach (ledgerflowEnvironmentBranches($tokens) as $violation) {
            $violations[] = "Modules/{$module}/app/{$file}: {$violation}";
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));

    expect($scanned)->not->toBeEmpty("Modules/{$module}/app produced no scannable production source.");
})->with('modules');

it('detects each environment-conditioned spelling in fabricated source', function (string $source, string $expected): void {
    expect(ledgerflowEnvironmentBranches(token_get_all($source)))->toBe([$expected]);
})->with([
    ['<?php if (app()->environment("testing")) { throw new RuntimeException("boom"); }', 'environment() call'],
    ['<?php if (App::environment("local")) {}', 'environment() call'],
    ['<?php if ($this->app->runningUnitTests()) {}', 'runningUnitTests() call'],
    ['<?php if ($app->environment("testing")) {}', 'environment() call'],
    ['<?php if (app()->isProduction()) {}', 'isProduction() call'],
    ['<?php if ($this->app->isLocal()) {}', 'isLocal() call'],
    ['<?php if (App::runningUnitTests()) {}', 'runningUnitTests() call'],
    ['<?php if (config("app.env") === "testing") {}', "config('app.env') call"],
    ['<?php if (\Illuminate\Support\Facades\Config::get("app.env") === "testing") {}', "get('app.env') call"],
    ['<?php if ($this->config->get("app.env") === "testing") {}', "get('app.env') call"],
    ['<?php if (app("env") === "testing") {}', "app('env') call"],
    ['<?php if (resolve("env") === "testing") {}', "resolve('env') call"],
    ['<?php if ($this->app->make("env") === "testing") {}', "make('env') call"],
    ['<?php if ($app->get("env") === "testing") {}', "get('env') call"],
    ['<?php if (App::make("env") === "testing") {}', "make('env') call"],
    ['<?php if (env("PAYMENTS_FAIL_AFTER_PUSH")) {}', 'env() call'],
    ['<?php if (getenv("PAYMENTS_FAIL_AFTER_PUSH")) {}', 'getenv() call'],
    ['<?php $flag = $_ENV["PAYMENTS_FAIL_AFTER_PUSH"];', '$_ENV'],
    ['<?php if (defined("PHPUNIT_COMPOSER_INSTALL")) {}', 'PHPUNIT constant'],
]);

it('does not mistake a comment or console detection for an environment branch', function (): void {
    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php /** THE THREE NAMES ARE LITERALS IN config/payments.php, not env(). */ final class M {}'
    )))->toBeEmpty();

    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php if ($this->app->runningInConsole()) { $this->commands([]); }'
    )))->toBeEmpty();

    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php final class M { public function isProduction(): bool { return true; } }'
    )))->toBeEmpty();

    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php $ms = config("payments.relay.statement_timeout_ms"); $c = app("db.connection");'
    )))->toBeEmpty();

    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php $a = $collection->get("app.env"); $b = $factory->make("env"); $c = $resolver->resolve("env");'
    )))->toBeEmpty();

    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php $a = $warehouse->isLocal(); $b = $release->isProduction();'
        .' $c = $deployment->environment(); $d = $runner->runningUnitTests();'
    )))->toBeEmpty();

    // A dynamic key is a known limit of the rule.
    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php $rows = $collection->get($key);'
    )))->toBeEmpty();
});

it('would still catch the seeder guard, which is excluded by scope rather than by spelling', function (): void {
    expect(ledgerflowEnvironmentBranches(token_get_all(
        '<?php if (! app()->environment(["local", "testing"])) { throw new RuntimeException("no"); }'
    )))->toBe(['environment() call']);
});
