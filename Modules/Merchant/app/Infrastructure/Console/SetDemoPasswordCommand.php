<?php

declare(strict_types=1);

namespace Modules\Merchant\Infrastructure\Console;

use Illuminate\Console\Command;
use Modules\Merchant\Database\Seeders\MerchantDatabaseSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

final class SetDemoPasswordCommand extends Command
{
    private const int MINIMUM_LENGTH = 12;

    // No password argument or option: the command line reaches shell history and `ps`, and the
    // environment reaches every child process.
    protected $signature = 'merchant:demo-password {email : A seeded fixture merchant user}';

    protected $description = 'Set a seeded fixture merchant password from a hidden interactive prompt';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (! in_array($email, MerchantDatabaseSeeder::fixtureEmails(), true)) {
            $this->components->error(
                'This command sets passwords for seeded fixture identities only: '
                .implode(', ', MerchantDatabaseSeeder::fixtureEmails()).'.'
            );

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('That fixture identity does not exist here. Run make db-seed first.');

            return self::FAILURE;
        }

        // Returns null without a terminal, which is the only refusal for a non-interactive caller.
        $password = $this->secret('New password');

        if (! is_string($password) || $password === '') {
            $this->components->error('No password was entered. This command reads one from an interactive prompt only.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < self::MINIMUM_LENGTH) {
            $this->components->error('Use at least '.self::MINIMUM_LENGTH.' characters.');

            return self::FAILURE;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->components->error('The two entries did not match.');

            return self::FAILURE;
        }

        $user->password = $password;
        $user->save();

        $this->components->info('Password updated for '.$email.'.');

        return self::SUCCESS;
    }
}
