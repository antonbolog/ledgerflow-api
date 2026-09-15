<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use RectorLaravel\Rector\ArrayDimFetch\EnvVariableToEnvHelperRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/Modules',
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

        // tests/bootstrap.php runs before the autoloader and must read the raw superglobals.
        EnvVariableToEnvHelperRector::class => [__DIR__.'/tests/bootstrap.php'],
        ServerVariableToRequestFacadeRector::class => [__DIR__.'/tests/bootstrap.php'],

        // The test pins ProjectPaymentEvent's class name as a literal wire contract; ::class would follow a rename.
        StringClassNameToClassConstantRector::class => [
            __DIR__.'/Modules/Payments/tests/Integration/PaymentOutboxRetryTest.php',
        ],
    ]);
