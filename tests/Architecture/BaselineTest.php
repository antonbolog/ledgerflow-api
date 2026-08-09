<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
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
| T2 owns module boundary rules: Payments Domain must not depend on Laravel,
| Eloquent, HTTP, Redis or queue types, and only ADR-listed Spatie base types
| are permitted. None of that can be expressed until modules exist, so it is
| deliberately absent here rather than stubbed.
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

arch('models stay in the models namespace')
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend(Model::class);

arch('no leftover skeleton debris')
    ->expect('App')
    ->not->toUse([Schema::class])
    ->ignoring('App\Providers');
