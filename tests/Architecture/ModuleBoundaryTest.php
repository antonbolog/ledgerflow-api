<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Module layer and cross-module dependencies
|--------------------------------------------------------------------------
|
| A filesystem scan rather than Pest arch() expectations, deliberately: arch()
| resolves a namespace through the autoloader and errors when it holds no
| classes, and Domain is legitimately empty in T2 for both modules. A rule that
| cannot be stated until the first class exists is a rule that gets written
| after the first violation.
|
| Honest about its limits: a dynamic class string (app('Some\Class')) still
| escapes this, and so does a helper called through a variable. It catches what
| a developer writes by habit, not what one writes to evade it.
|
*/

const LEDGERFLOW_MODULES_PATH = __DIR__.'/../../Modules';

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

/**
 * Tokenised source for every PHP file under a directory, memoised because seven
 * rules scan overlapping trees.
 *
 * @return array<string, list<array{0: int, 1: string, 2: int}|string>>
 */
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

/**
 * Namespaced names a file depends on.
 *
 * @return array<string, list<string>>
 */
function ledgerflowReferences(string $directory): array
{
    $references = [];

    foreach (ledgerflowTokens($directory) as $relative => $tokens) {
        $names = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // The file's own `namespace X\Y;` is where it lives, not something
            // it depends on. Counting it would make every Domain file fail the
            // Domain rule the moment one exists.
            if (ledgerflowPreviousToken($tokens, $index) === T_NAMESPACE) {
                continue;
            }

            $name = ltrim($token[1], '\\');

            // Single-segment names are global (RuntimeException,
            // DateTimeImmutable). Without this, `\RuntimeException` fails while
            // `use RuntimeException;` passes, and the rule measures spelling.
            if (! str_contains($name, '\\')) {
                continue;
            }

            $names[] = $name;
        }

        $references[$relative] = array_values(array_unique($names));
    }

    return $references;
}

/**
 * Laravel global helper calls, which emit no name token and therefore escape
 * every namespace rule above.
 *
 * @return array<string, list<string>>
 */
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

            // A call, not a method, a static call, or a declaration.
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

/*
| Each rule below collects violations and asserts once, rather than asserting
| per reference inside the loop. Two reasons, both learned from running it: an
| empty Domain makes a per-reference assertion perform no assertions at all, so
| PHPUnit marks the rule risky and it enforces nothing it can prove; and a
| failure reports every violation at once instead of stopping at the first.
*/

it('lets Domain reference nothing outside its own namespace', function (string $module): void {
    $allowed = "Modules\\{$module}\\Domain\\";
    $violations = [];

    foreach (ledgerflowReferences(ledgerflowModulePath($module).'/app/Domain') as $file => $names) {
        foreach ($names as $name) {
            if (! str_starts_with($name, $allowed)) {
                $violations[] = "Modules/{$module}/app/Domain/{$file} references {$name}.";
            }
        }
    }

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

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

    // Cross-context work goes through a published Application contract. This is
    // the whole reason MerchantContext lives in Application: Payments
    // Application must be able to import it, and an allowlist that forbade that
    // would make the contract unusable by the only consumer it exists for.
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
    // Derived from disk over every discovered pair, so a third module is
    // governed the moment it exists rather than when someone edits this file.
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
    // Stricter than the general rule above, and directional on purpose: the
    // event-sourced context may ask Merchant who owns a payment; Merchant has
    // no business knowing payments exist.
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

it('gives every module all four layers', function (string $module): void {
    // Required, not merely permitted. A module missing a layer directory is a
    // module whose layer rules scan nothing, so its boundaries pass vacuously
    // and forever. An empty Domain is a deliberate state; an absent one hides
    // the rule that governs it.
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
    // Providers is framework wiring the module manager discovers, kept beside
    // the layers rather than inside one.
    $permitted = ['Domain', 'Application', 'Infrastructure', 'Http', 'Providers'];
    $appPath = ledgerflowModulePath($module).'/app';

    foreach (Finder::create()->directories()->depth(0)->in($appPath) as $directory) {
        expect($directory->getFilename())->toBeIn($permitted);
    }

    // A class dropped straight into app/ would be governed by no layer rule at
    // all, which is a quieter hole than an unexpected directory.
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
