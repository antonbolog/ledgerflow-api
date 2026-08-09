<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Intentionally empty. T1 establishes only the seeding command contract:
     * `db-seed` must run, exit zero, and be repeatable. Deterministic
     * identities are out of scope for the foundation slice.
     *
     * T2 owns merchant-user identity and populates this seeder with the two
     * deterministic merchants and users required by the development
     * environment specification's acceptance evidence.
     *
     * @see docs/specifications/foundation/development-environment.md
     */
    public function run(): void
    {
        //
    }
}
