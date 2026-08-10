<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Merchant\Database\Seeders\MerchantDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Delegates to each module's own seeder rather than reaching into module
     * models directly, so a module owns its fixtures the same way it owns its
     * migrations.
     *
     * `make db-seed` is repeatable, and the contract is precisely this: a
     * re-run creates no duplicate identities, never moves an existing email to
     * a different merchant, and never rewrites a password hash. It does not
     * promise byte-identical rows. updateOrCreate() and save() will refresh
     * timestamps if a fixture's non-key attributes ever drift from what is
     * stored, which is a correction rather than a violation.
     *
     * @see docs/specifications/foundation/development-environment.md
     */
    public function run(): void
    {
        $this->call(MerchantDatabaseSeeder::class);
    }
}
