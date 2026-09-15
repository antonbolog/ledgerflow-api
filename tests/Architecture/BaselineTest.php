<?php

declare(strict_types=1);

arch('source files declare strict types')
    ->expect(['App', 'Modules\Merchant', 'Modules\Payments'])
    ->toUseStrictTypes();

arch('no debugging helpers reach the codebase')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('env() is read only through configuration')
    // env() returns null once configuration is cached.
    ->expect('env')
    ->not->toBeUsed();
