<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Database\Seeders\PersonalAccessClientSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

// Authenticates with a real Passport bearer token, never Passport::actingAs, which bypasses the guard and token table.
final class Initiation
{
    public const string KEY = 'slice-1-idempotency-key';

    /**
     * @param  array<string, mixed>  $payload  merged over the canonical valid body
     * @param  array<string, string>|null  $headers  null keeps the default key; [] sends none
     */
    public static function send(array $payload = [], ?array $headers = null, ?Merchant $merchant = null): TestResponse
    {
        $token = self::token($merchant);

        // The token guard memoises its user across requests in one test, so a later request would keep the first merchant.
        Auth::forgetGuards();

        return test()
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/payments', [...self::body(), ...$payload], $headers ?? ['Idempotency-Key' => self::KEY]);
    }

    /** @return array<string, mixed> */
    public static function body(): array
    {
        return ['amount_minor' => 1_250, 'currency' => 'USD'];
    }

    public static function token(?Merchant $merchant = null): string
    {
        $merchant ??= Merchant::factory()->create();

        test()->seed(PersonalAccessClientSeeder::class);

        return User::factory()->for($merchant)->create()->createToken('slice-1')->accessToken;
    }
}
