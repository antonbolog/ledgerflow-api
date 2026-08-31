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

/**
 * Fully-qualified class names a module's Domain may reference besides its own
 * namespace. EXACT matches, never prefixes: a prefix of Spatie\EventSourcing\
 * would admit Projector, StoredEvent and the Eloquent models alongside the two
 * types ADR 0002 permits.
 *
 * Recorded in docs/adr/0002-payments-domain-event-sourcing-allowlist.md. That
 * document explains the decision; this constant is what enforces it.
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

/**
 * The Domain rule as a pure function over a reference map, so it can be run
 * against fabricated input. A rule that can only run against the current tree
 * is a rule whose failure path is never tested.
 *
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

it('lets Domain reference nothing outside its own namespace and the ADR 0002 allowlist', function (string $module): void {
    $violations = ledgerflowDomainViolations(
        $module,
        ledgerflowReferences(ledgerflowModulePath($module).'/app/Domain'),
    );

    expect($violations)->toBeEmpty(implode(PHP_EOL, $violations));
})->with('modules');

/*
| The rule above passes vacuously while a Domain is empty, and it will keep
| passing for Merchant forever. The tests below exercise the SAME function
| against fabricated references, so the allowlist's failure path is tested
| rather than assumed.
*/

it('grants the ADR 0002 exception to exactly two classes in exactly one module', function (): void {
    // The behavioural tests below prove what the rule DOES with the allowlist.
    // This one pins the allowlist itself, by value: a third entry, a second
    // module key, or a substitution is a change to the ADR 0002 decision and has
    // to fail here rather than quietly widen what Domain may reference.
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
    // The projector is the one a careless allowlist admits: it is the third type
    // the spike extended, and a namespace-prefix rule would let it through.
    expect(ledgerflowDomainViolations('Payments', ['Fake.php' => [$forbidden]]))->not->toBeEmpty();
})->with([
    Projector::class,
    EventVersion::class,
    DB::class,
]);

it('grants the allowlist to Payments only', function (): void {
    // Merchant has no event-sourced aggregate and no exception. If this passes,
    // the allowlist has gone global and ADR 0002 is misdescribed.
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

it('never delegates concurrency classification to laravel', function (): void {
    // Laravel's ConcurrencyErrorDetector matches 40001 by code and 40P01 only
    // through the English message 'deadlock detected', and does not recognise
    // 55P03 AT ALL — T3 measured it returning false for a real PostgreSQL 18.4
    // lock conflict, the case this specification cares most about. Adopting it
    // later would silently narrow the allowlist RetryingAtomicTransaction owns,
    // and nothing else in the suite would notice: the runner would still retry
    // two of the three states and simply stop retrying the third.
    //
    // EXACT NAMES, NOT PREFIXES, matching the ADR 0002 allowlist's discipline: a
    // prefix of Illuminate\Database\ would forbid the ConnectionInterface and
    // DeadlockException the Infrastructure layer legitimately depends on.
    // ::class, not string literals — the same idiom as the ADR 0002 allowlist at
    // the top of this file, and it means a Laravel rename cannot leave this rule
    // silently matching a name that no longer exists.
    $forbidden = [
        DetectsConcurrencyErrors::class,
        ConcurrencyErrorDetector::class,
    ];

    // Derived from disk over every module, so a third module is governed the
    // moment it exists. ledgerflowTokens() excludes tests/, so this rule is about
    // production source — a test may still NAME the detector to describe it.
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

/*
|--------------------------------------------------------------------------
| Payments relay source must never opt into after-commit dispatch
|--------------------------------------------------------------------------
|
| Queue::shouldDispatchAfterCommit() checks ShouldQueueAfterCommit and a truthy
| $afterCommit property BEFORE it reads the connection's after_commit config
| (Queue/Queue.php:396-408). Either would silently override the literal
| after_commit => false on the payments-projection connection, and the third
| spelling — ->afterCommit() at the dispatch site — would do the same while
| leaving NO runtime trace for a reflection test to find.
|
| Immediate publication inside the still-open transaction is what turns a crash
| before COMMIT into one documented safe duplicate instead of a lost projection,
| so this is a correctness rule.
|
| SCOPED TO ONE DIRECTORY, DELIBERATELY. A repository-wide ban would also forbid a
| future job that legitimately wants after-commit dispatch, which is a decision no
| architecture test owns. Modules/Payments/app/Infrastructure/Relay is the whole
| publisher — the message AND the dispatch site — and it is exactly where the third
| spelling will appear, because ->afterCommit() is written at the push, not on the
| message. Scanning only the message class would leave that spelling unguarded in
| the one file most likely to carry it.
|
*/

const LEDGERFLOW_RELAY_SOURCE = __DIR__.'/../../Modules/Payments/app/Infrastructure/Relay';

/**
 * The consumer side, added with T8's dispatch-adjacent code.
 *
 * SCANNED FOR THE SAME THREE SPELLINGS, because the ban is about the DISPATCH of
 * ProjectPaymentEvent and dispatch sites are not confined to the publisher. A
 * future repair command, a rebuild path or a retry that re-published an envelope
 * would most naturally be written here, and ->afterCommit() at such a site leaves
 * no runtime trace for a reflection test to find.
 *
 * The Console directory joins them for the same reason: `payments:outbox:retry`
 * makes a row eligible again, and the obvious next step someone adds to it is a
 * dispatch.
 */
const LEDGERFLOW_DISPATCH_ADJACENT_SOURCE = [
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Relay',
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Projection',
    __DIR__.'/../../Modules/Payments/app/Infrastructure/Console',
];

/**
 * The three spellings, found in a token stream.
 *
 * A pure function over tokens rather than a scan of the real file, so the failure
 * path is exercised against fabricated input — the same discipline
 * ledgerflowDomainViolations() already follows. Comments cannot trigger it:
 * token_get_all() yields one T_COMMENT/T_DOC_COMMENT token for a whole comment, so
 * the docblock naming all three traps is invisible here.
 *
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

        // $afterCommit, declared or assigned.
        if ($type === T_VARIABLE && strtolower($text) === '$aftercommit') {
            $found[] = '$afterCommit property';

            continue;
        }

        // ->afterCommit(). PHP method names are case-insensitive, so the comparison
        // is too; a rule that matched only the canonical casing would measure
        // spelling rather than behaviour.
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

    // A DIRECTORY SCAN CAN GO EMPTY WITHOUT THE PATH BEING WRONG — a rename inside
    // it, a move to another layer — and an empty scan reports success. The two
    // files guaranteed to be here are named; the count catches the rest
    // disappearing.
    expect($scanned)->toContain('ProjectPaymentEvent.php')
        ->toContain('PaymentProjectionConsumer.php');

    $expected = array_sum(array_map(
        fn (string $directory): int => count(glob($directory.'/*.php') ?: []),
        LEDGERFLOW_DISPATCH_ADJACENT_SOURCE,
    ));

    expect(count($scanned))->toBeGreaterThanOrEqual($expected);
});

it('detects each after-commit spelling in fabricated source', function (string $source, string $expected): void {
    // Without these, a rule that returned a constant empty array would leave the
    // test above green forever.
    expect(ledgerflowAfterCommitOptIns(token_get_all($source)))->toBe([$expected]);
})->with([
    ['<?php class M implements \Illuminate\Contracts\Queue\ShouldQueueAfterCommit {}', 'implements ShouldQueueAfterCommit'],
    ['<?php final class M { public bool $afterCommit = true; }', '$afterCommit property'],
    ['<?php dispatch(new M())->afterCommit();', '->afterCommit() call'],
    ['<?php dispatch(new M())->AfterCommit();', '->afterCommit() call'],
]);

it('does not mistake a comment or an unrelated name for an opt-in', function (): void {
    // The real file's docblock names all three traps in prose. If comments counted,
    // the rule above would fail on the very documentation that explains it.
    expect(ledgerflowAfterCommitOptIns(token_get_all(
        '<?php /** never use $afterCommit, ->afterCommit() or ShouldQueueAfterCommit */ final class M {}'
    )))->toBeEmpty();

    expect(ledgerflowAfterCommitOptIns(token_get_all(
        '<?php final class M { public function afterCommitHook(): void {} }'
    )))->toBeEmpty();
});
