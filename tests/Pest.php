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

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Architecture');
