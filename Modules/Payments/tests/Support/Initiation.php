<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Database\Seeders\PersonalAccessClientSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use Modules\Payments\Http\Requests\PaymentIntentHeaders;

// Authenticates with a real Passport bearer token, never Passport::actingAs, which bypasses the guard and token table.
final class Initiation
{
    public const string KEY = 'slice-1-idempotency-key';

    /** @var array<string, string> intent id per idempotency key */
    private static array $intents = [];

    /**
     * @param  array<string, mixed>  $payload  merged over the canonical valid body
     * @param  array<string, string>|null  $headers  null keeps the default key; [] sends none; others override the intent headers
     */
    public static function send(array $payload = [], ?array $headers = null, ?Merchant $merchant = null): TestResponse
    {
        $token = self::token($merchant);

        // The token guard memoises its user across requests in one test, so a later request would keep the first merchant.
        Auth::forgetGuards();

        return test()
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/payments', [...self::body(), ...$payload], self::headers($headers));
    }

    /**
     * @param  array<string, string>|null  $headers
     * @return array<string, string>
     */
    public static function headers(?array $headers = null): array
    {
        if ($headers === []) {
            return [];
        }

        $headers ??= ['Idempotency-Key' => self::KEY];

        return [...self::intentHeaders($headers['Idempotency-Key'] ?? ''), ...$headers];
    }

    /**
     * One intent per key, so a repeated key stays a replay rather than a crossed binding.
     * The expiry is fresh per request: a cached one would outlive the test that created it.
     *
     * @return array<string, string>
     */
    public static function intentHeaders(string $idempotencyKey): array
    {
        return [
            PaymentIntentHeaders::INTENT_ID => self::$intents[$idempotencyKey] ??= (string) Str::uuid(),
            PaymentIntentHeaders::EXPIRES_AT => CarbonImmutable::now('UTC')->addSeconds(30)->format('Y-m-d\TH:i:s.v\Z'),
        ];
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
