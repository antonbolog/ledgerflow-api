<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The glob binds every module's Feature tests; without it PHPUnit runs them without RefreshDatabase.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../Modules/*/tests/Feature');

// No RefreshDatabase: its wrapping transaction would turn the commits these tests observe into savepoints.
pest()->extend(TestCase::class)
    ->in('../Modules/*/tests/Integration');

pest()->extend(TestCase::class)
    ->in('Architecture');
