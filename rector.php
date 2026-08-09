<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Set\LaravelSetList;

/**
 * Deliberately minimal for the foundation slice.
 *
 * Two sets only: the PHP 8.4 language set, which matches the pinned runtime,
 * and Laravel code quality. Broader prepared sets (dead code, type
 * declarations) rewrite aggressively and there is no project code yet for them
 * to be judged against — widening the rule set belongs with the milestone that
 * has code worth the churn.
 *
 * `quality` runs this with --dry-run and never writes. The one-time write pass
 * that normalises the skeleton is a deliberate, separate act.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ])
    ->withImportNames(importShortClasses: false)
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',

        // tests/bootstrap.php runs BEFORE the autoloader, so Env::get() and
        // Request::server() do not exist yet. It also reads the raw superglobals
        // on purpose: it exists precisely to check what Laravel's helpers would
        // resolve, so rewriting it to use those helpers would delete the guard.
        EnvVariableToEnvHelperRector::class => [__DIR__.'/tests/bootstrap.php'],
        ServerVariableToRequestFacadeRector::class => [__DIR__.'/tests/bootstrap.php'],
    ]);
