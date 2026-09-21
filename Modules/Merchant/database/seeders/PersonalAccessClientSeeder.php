<?php

declare(strict_types=1);

namespace Modules\Merchant\Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;

class PersonalAccessClientSeeder extends Seeder
{
    public const string CLIENT_NAME = 'ledgerflow-local-personal-access';

    private const string PROVIDER = 'users';

    public function __construct(private readonly ClientRepository $clients) {}

    public function run(): void
    {
        // `db:seed --force` bypasses Laravel's production prompt, so the environment is the guard.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Refusing to create a personal access client outside the local and testing environments. '
                .'It mints merchant bearer tokens without an authorization redirect, which only local '
                .'tooling such as make demo-token may do.'
            );
        }

        if ($this->existingClient() === null) {
            $this->clients->createPersonalAccessGrantClient(self::CLIENT_NAME, self::PROVIDER);
        }
    }

    private function existingClient(): ?Client
    {
        // grant_types is a text column, so no JSON predicate narrows this query on PostgreSQL.
        return Passport::client()->newQuery()
            ->where('provider', self::PROVIDER)
            ->where('revoked', false)
            ->get()
            ->first(fn (Client $client): bool => $client->hasGrantType('personal_access'));
    }
}
