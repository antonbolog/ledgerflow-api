<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and get a freshly migrated database on
| every test. That truncation is exactly why tests/bootstrap.php refuses to
| run against anything but ledgerflow_test.
|
| Unit tests stay framework-free and touch no database. Architecture tests
| inspect source files only and need neither.
|
*/

/*
| The glob is load-bearing. Pest's in() resolves a relative target against the
| tests directory, then runs glob(), then realpath() — so this matches every
| module that has Feature tests, today and later, and a module without them
| contributes nothing. PHPUnit discovers the same paths; without this binding it
| would discover them with the wrong base class and no RefreshDatabase.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../Modules/*/tests/Feature');

pest()->extend(TestCase::class)
    ->in('Architecture');
