<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and run against a migrated database.
| RefreshDatabase migrates ONCE PER PROCESS — `migrate:fresh`, gated on the
| static RefreshDatabaseState::$migrated and triggered by whichever
| RefreshDatabase test runs first — and then isolates each individual test by
| wrapping it in a transaction that is rolled back afterwards. It does not
| re-migrate, and it does not truncate, per test.
|
| That once-per-process `migrate:fresh` DROPS EVERY TABLE, which is exactly why
| tests/bootstrap.php refuses to run against anything but ledgerflow_test.
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

/*
| Integration deliberately gets TestCase WITHOUT RefreshDatabase.
|
| RefreshDatabase wraps every test in a transaction, which turns the production
| DB::transaction() into a SAVEPOINT: TransactionCommitting never fires at depth 1,
| no second connection can observe a commit, and an "all three rows exist"
| assertion would pass even if the handler opened no transaction at all. These
| tests commit real rows and provision and truncate their own schema, exactly as
| the T3/T4 spikes did.
|
| Adding RefreshDatabase here to fix a "relation does not exist" error would make
| the error go away and destroy the suite's only reason to exist. See
| Modules/Payments/tests/Integration/IntegrationDatabase.php.
*/
pest()->extend(TestCase::class)
    ->in('../Modules/*/tests/Integration');

pest()->extend(TestCase::class)
    ->in('Architecture');
