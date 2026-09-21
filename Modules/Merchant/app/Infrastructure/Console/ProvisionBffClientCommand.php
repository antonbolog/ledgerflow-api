<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Console;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

final class ProvisionBffClientCommand extends Command
{
    public const string CLIENT_NAME = 'ledgerflow-bff';

    // League matches loopback IP literals without the port, which would widen the exact-match
    // redirect contract to every port on that interface.
    private const array REFUSED_HOSTS = ['127.0.0.1', '::1', '[::1]'];

    protected $signature = 'merchant:bff-client {redirect-uri : The exact callback URI the BFF is registered for}';

    protected $description = 'Register the confidential first-party BFF client and print its secret once';

    public function __construct(private readonly ClientRepository $clients)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $redirectUri = (string) $this->argument('redirect-uri');
        $parts = parse_url($redirectUri);

        if ($parts === false
            || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['fragment'])
            || in_array(mb_strtolower($parts['host']), self::REFUSED_HOSTS, true)
        ) {
            $this->components->error(
                'The redirect URI must be absolute, carry no fragment, and must not name a loopback '
                .'IP address, whose port OAuth ignores. Use a hostname such as http://localhost:3000/auth/callback.'
            );

            return self::FAILURE;
        }

        // Revoked rows stay: their tokens must keep failing validation, and rotation is exactly
        // revoking the active client and registering a successor with a new id and secret.
        $active = Passport::client()->newQuery()
            ->where('name', self::CLIENT_NAME)
            ->where('revoked', false)
            ->exists();

        if ($active) {
            $this->components->error(
                'An active '.self::CLIENT_NAME.' client already exists, and Passport keeps only a hash '
                .'of its secret. Rotate it by revoking that client and running this command again.'
            );

            return self::FAILURE;
        }

        $client = $this->clients->createAuthorizationCodeGrantClient(self::CLIENT_NAME, [$redirectUri]);

        /** @var string $clientId */
        $clientId = $client->getKey();

        // The plaintext exists only on this creating instance and is never written to a log.
        $this->components->info('Registered '.self::CLIENT_NAME.'. The secret below is shown once and is stored only as a hash.');
        $this->line('PASSPORT_BFF_CLIENT_ID='.$clientId);
        $this->line('client_secret='.$client->plainSecret);

        return self::SUCCESS;
    }
}
