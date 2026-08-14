<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;

/**
 * Shared request builder for the initiation endpoint.
 *
 * A class rather than global test functions: Slice 2 adds a second test file that
 * needs the same builder, and a global function defined in one Pest file and used
 * from another works only by load order. PSR-4 removes the question.
 *
 * IT ALWAYS AUTHENTICATES WITH A REAL SANCTUM BEARER TOKEN, never with
 * Sanctum::actingAs. actingAs installs the user straight into the guard, so it
 * would still pass with a misconfigured guard or no personal_access_tokens table
 * at all — the exact false-green the plan records as FG-4. Paying one createToken()
 * per request buys every 202 in the suite the same proof.
 *
 * Deterministic by default. Nothing here is random, so a rerun sends identical
 * bytes.
 */
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

        // forgetGuards() is REQUIRED, not decorative — the same trap T2 documents in
        // MerchantContextTest. Illuminate\Auth\RequestGuard memoises the resolved
        // user (`if (! is_null($this->user)) return $this->user;`) and AuthManager
        // memoises the guard, and a Laravel test reuses ONE application across every
        // request it makes. Without this, a second request in the same test keeps the
        // FIRST token's user, so two merchants silently become one — which turns the
        // merchant-scoping tests green for the wrong reason and would have hidden a
        // real cross-tenant bug.
        //
        // Production is unaffected: each request gets its own container and guard.
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

        return User::factory()->for($merchant)->create()->createToken('slice-1')->plainTextToken;
    }
}
