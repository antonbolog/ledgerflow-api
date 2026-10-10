<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;
use Modules\Merchant\Database\Seeders\PersonalAccessClientSeeder;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use Modules\Payments\Tests\Support\Initiation;

/** @return array{0: TestResponse, 1: TestResponse, 2: TestResponse} */
function callPaymentRoutes(?string $authorization, string $paymentId): array
{
    Auth::forgetGuards();
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];
    $initiate = test()->postJson('/api/payments', Initiation::body(), [...$headers, ...Initiation::headers()]);

    Auth::forgetGuards();
    $show = test()->withHeaders($headers)->getJson('/api/payments/'.$paymentId);

    Auth::forgetGuards();
    $recover = test()->withHeaders($headers)->getJson('/api/payment-intents');

    return [$initiate, $show, $recover];
}

function personalAccessToken(User $user, string $name): string
{
    test()->seed(PersonalAccessClientSeeder::class);

    return $user->createToken($name)->accessToken;
}

/** @return array<string, mixed> */
function jwtClaims(string $jwt): array
{
    /** @var array<string, mixed> $claims */
    $claims = json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);

    return $claims;
}

it('authenticates each merchant from its own real Passport bearer token', function (): void {
    $first = Merchant::factory()->create();
    $second = Merchant::factory()->create();

    Initiation::send(merchant: $first)->assertStatus(202);
    Initiation::send(merchant: $second)->assertStatus(202);

    expect(DB::table('payment_idempotency_receipts')->orderBy('merchant_id')->pluck('merchant_id')->all())
        ->toBe([$first->getKey(), $second->getKey()]);
});

it('issues personal access tokens as one-hour Passport JWTs backed by a token row', function (): void {
    $user = User::factory()->for(Merchant::factory())->create();

    $claims = jwtClaims(personalAccessToken($user, 'claims'));

    expect($claims['exp'])->toBeNumeric()
        ->and($claims['iat'])->toBeNumeric()
        ->and((int) $claims['exp'] - (int) $claims['iat'])->toBe(3600)
        ->and($claims['sub'])->toBe((string) $user->getKey())
        ->and(Passport::token()->newQuery()->whereKey($claims['jti'])->where('user_id', $user->getKey())->exists())->toBeTrue();
});

it('answers every unusable credential with the unchanged 401 problem document', function (Closure $credential): void {
    $paymentId = (string) Str::uuid7();
    [$expectedInitiate, $expectedShow, $expectedRecover] = callPaymentRoutes(null, $paymentId);

    $expectedInitiate->assertStatus(401);
    $expectedShow->assertStatus(401);
    $expectedRecover->assertStatus(401);

    [$initiate, $show, $recover] = callPaymentRoutes($credential(), $paymentId);

    foreach ([[$initiate, $expectedInitiate], [$show, $expectedShow], [$recover, $expectedRecover]] as [$actual, $expected]) {
        $actual->assertStatus(401);

        expect($actual->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and($actual->getContent())->toBe($expected->getContent());
    }
})->with([
    'a malformed bearer token' => fn (): string => 'Bearer not-a-token',
    'a former Sanctum-format token' => fn (): string => 'Bearer 1|'.Str::random(40),
    'a revoked Passport token' => function (): string {
        $user = User::factory()->for(Merchant::factory())->create();
        $token = personalAccessToken($user, 'revoked');

        Passport::token()->newQuery()->whereKey(jwtClaims($token)['jti'])->update(['revoked' => true]);

        return 'Bearer '.$token;
    },
    'a token signature from another key' => function (): string {
        $user = User::factory()->for(Merchant::factory())->create();
        [$header, $payload] = explode('.', personalAccessToken($user, 'forged'));

        return 'Bearer '.$header.'.'.$payload.'.'.rtrim(strtr(base64_encode(random_bytes(512)), '+/', '-_'), '=');
    },
]);

it('accepts the token it revokes until the moment it is revoked', function (): void {
    $user = User::factory()->for(Merchant::factory())->create();
    $token = personalAccessToken($user, 'control');
    $paymentId = (string) Str::uuid7();

    [, $before, $recoverBefore] = callPaymentRoutes('Bearer '.$token, $paymentId);
    $before->assertStatus(404);
    $recoverBefore->assertStatus(200);

    Passport::token()->newQuery()->whereKey(jwtClaims($token)['jti'])->update(['revoked' => true]);

    [, $after, $recoverAfter] = callPaymentRoutes('Bearer '.$token, $paymentId);
    $after->assertStatus(401);
    $recoverAfter->assertStatus(401);
});

it('never authenticates the resource api from a merchant_web session', function (): void {
    $user = User::factory()->for(Merchant::factory())->create();

    Auth::guard('merchant_web')->login($user);

    expect(Auth::guard('merchant_web')->id())->toBe($user->getKey());

    $paymentId = (string) Str::uuid7();
    [$initiate, $show, $recover] = callPaymentRoutes(null, $paymentId);

    $initiate->assertStatus(401);
    $show->assertStatus(401);
    $recover->assertStatus(401);

    Auth::forgetGuards();

    expect(Auth::guard('merchant_web')->id())->toBe($user->getKey())
        ->and(DB::table('payment_idempotency_receipts')->count())->toBe(0);
});

it('does not report rejected bearer tokens as application errors', function (): void {
    $handler = resolve(ExceptionHandler::class);

    expect($handler->shouldReport(OAuthServerException::accessDenied('Access token has been revoked')))->toBeFalse()
        ->and($handler->shouldReport(OAuthServerException::serverError('Unsupported code challenge method')))->toBeTrue();
});
