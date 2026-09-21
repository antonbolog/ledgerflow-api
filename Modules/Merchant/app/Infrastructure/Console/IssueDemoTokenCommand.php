<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use RuntimeException;

final class IssueDemoTokenCommand extends Command
{
    protected $signature = 'merchant:demo-token {email : A seeded fixture merchant user}';

    protected $description = 'Print one short-lived Passport bearer token for a seeded fixture merchant user';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No merchant user has that email. Run make db-seed first.');

            return self::FAILURE;
        }

        try {
            // Passport refuses without a personal access client, which only the local seeders create.
            $token = $user->createToken('ledgerflow-demo')->accessToken;
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage().' Run make db-seed first.');

            return self::FAILURE;
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
