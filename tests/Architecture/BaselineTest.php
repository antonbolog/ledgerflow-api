<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Architecture baseline
|--------------------------------------------------------------------------
|
| Framework-level invariants only. These are the rules that can be stated
| before any domain code exists, and they exist now so the codebase never
| accumulates violations that a later slice has to retrofit.
|
| Module layer and cross-module rules live in ModuleBoundaryTest. This file
| stays scoped to invariants that hold across the whole codebase.
|
*/

arch('source files declare strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging helpers reach the codebase')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() is read only through configuration')
    // Reading env() outside config/ breaks once configuration is cached in any
    // deployed environment, and the failure is silent: values simply become
    // null. config/ is the one place it is legitimate.
    ->expect('env')
    ->not->toBeUsed();

arch('module source files declare strict types')
    ->expect(['Modules\Merchant', 'Modules\Payments'])
    ->toUseStrictTypes();

arch('no leftover skeleton debris')
    ->expect('App')
    ->not->toUse([Schema::class])
    ->ignoring('App\Providers');
