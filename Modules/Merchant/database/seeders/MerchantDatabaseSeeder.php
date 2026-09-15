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
        // `db:seed --force` bypasses Laravel's production prompt, so the environment is the guard.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Refusing to seed fixtures outside the local and testing environments. '
                .'These identities are development data: they carry fixed emails and a '
                .'generated password, and nothing about them is meant to exist anywhere real.'
            );
        }

        DB::transaction(function (): void {
            foreach (self::IDENTITIES as $identity) {
                $merchant = Merchant::query()->updateOrCreate(
                    ['slug' => $identity['slug']],
                    ['name' => $identity['name']],
                );

                $user = User::query()->firstOrNew(['email' => $identity['email']]);

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

                // Insert only: the hashed cast re-salts on every assignment, so re-seeding would rewrite the row.
                if (! $user->exists) {
                    $user->password = Str::password(32);
                }

                $user->save();
            }
        });
    }
}
