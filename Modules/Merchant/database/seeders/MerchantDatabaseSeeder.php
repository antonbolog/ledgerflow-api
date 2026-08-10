<?php

declare(strict_types=1);

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use RuntimeException;

class MerchantDatabaseSeeder extends Seeder
{
    /**
     * The two identities the Payments slice is proved against.
     *
     * Two, not one: docs/testing/payments-resilience.md requires a second
     * merchant so cross-merchant isolation can actually be demonstrated rather
     * than assumed.
     *
     * Keyed by slug and email, never by id. Ids come from a PostgreSQL sequence
     * and are not stable across a rebuild; these natural keys are what tests
     * and the demo reference.
     *
     * @var list<array{slug: string, name: string, email: string, user: string}>
     */
    private const array IDENTITIES = [
        [
            'slug' => 'northwind-trading',
            'name' => 'Northwind Trading',
            'email' => 'owner@northwind.test',
            'user' => 'Nora Northwind',
        ],
        [
            'slug' => 'atlantic-goods',
            'name' => 'Atlantic Goods',
            'email' => 'owner@atlantic.test',
            'user' => 'Ari Atlantic',
        ],
    ];

    public function run(): void
    {
        // Fixtures are development and test data, and nothing else. `db:seed
        // --force` exists precisely to bypass Laravel's own production prompt,
        // so the Makefile passes it and the prompt cannot be relied on. This
        // refuses on the environment instead, which is the thing that actually
        // distinguishes a developer's database from anyone else's.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Refusing to seed fixtures outside the local and testing environments. '
                .'These identities are development data: they carry fixed emails and a '
                .'generated password, and nothing about them is meant to exist anywhere real.'
            );
        }

        // One transaction around the whole fixture set. A conflict on the
        // second identity must not leave the first one committed: a seeder that
        // half-applies is not deterministic, and the failure would be invisible
        // until something downstream found one merchant where it expected two.
        DB::transaction(function (): void {
            foreach (self::IDENTITIES as $identity) {
                $merchant = Merchant::query()->updateOrCreate(
                    ['slug' => $identity['slug']],
                    ['name' => $identity['name']],
                );

                $user = User::query()->firstOrNew(['email' => $identity['email']]);

                // users.email is globally unique, inherited from the framework
                // skeleton. Matching on it and then assigning merchant_id would
                // silently move a real user's identity to a fixture merchant,
                // so this refuses instead. Tenant-scoped email uniqueness is a
                // real question and belongs to the task with a signup flow.
                if ($user->exists && $user->merchant_id !== $merchant->id) {
                    throw new RuntimeException(
                        "Refusing to reassign {$identity['email']} from merchant "
                        ."{$user->merchant_id} to {$merchant->id}."
                    );
                }

                $user->name = $identity['user'];
                $user->merchant_id = $merchant->id;

                if ($user->email_verified_at === null) {
                    $user->email_verified_at = now();
                }

                // Random, and only on insert.
                //
                // Random because a fixed password in a repository that goes
                // public is a credential regardless of how local it is meant to
                // be, and nothing needs to know it: these identities are reached
                // through tokens, not through a login form.
                //
                // Only on insert because the 'hashed' cast salts on every
                // assignment, so setting it unconditionally would rewrite the
                // row on every run and make `make db-seed` non-idempotent in a
                // way nothing would notice. Together that means the hash is
                // generated once and preserved by every later run.
                if (! $user->exists) {
                    $user->password = Str::password(32);
                }

                $user->save();
            }
        });
    }
}
