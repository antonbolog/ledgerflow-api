<?php

declare(strict_types=1);

use App\Http\Problem\ProblemType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\Merchant;
use Modules\Payments\Tests\Support\Initiation;

/** @return array<string, mixed> */
function openApiDocument(): array
{
    $path = base_path('docs/api/openapi.json');

    expect($path)->toBeReadableFile();

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $document;
}

/** @return array<string, mixed> */
function openApiOperation(string $path, string $method): array
{
    /** @var array<string, array<string, array<string, mixed>>> $paths */
    $paths = openApiDocument()['paths'];

    expect($paths)->toHaveKey($path)
        ->and($paths[$path])->toHaveKey($method);

    return $paths[$path][$method];
}

/** @return list<string> */
function openApiBodyProperties(string $path, string $method, int $status, string $mediaType): array
{
    /** @var array<string, mixed> $schema */
    $schema = openApiOperation($path, $method)['responses'][(string) $status]['content'][$mediaType]['schema'];

    if (isset($schema['$ref'])) {
        $name = (string) Str::afterLast((string) $schema['$ref'], '/');
        /** @var array<string, mixed> $schema */
        $schema = openApiDocument()['components']['schemas'][$name];
    }

    /** @var array<string, mixed> $properties */
    $properties = $schema['properties'];

    return array_keys($properties);
}

function projectPayment(int $merchantId, string $paymentId): void
{
    DB::table('payment_details')->insert([
        'payment_id' => $paymentId,
        'source_event_id' => (string) Str::uuid7(),
        'merchant_id' => $merchantId,
        'amount_minor' => 1_250,
        'currency' => 'USD',
        'status' => 'initiated',
        'aggregate_version' => 1,
        'initiated_at' => '2026-08-21T10:11:12.123456+00:00',
    ]);
}

it('is valid JSON declaring an OpenAPI 3.1 document', function (): void {
    $document = openApiDocument();

    expect($document['openapi'])->toStartWith('3.1.')
        ->and($document['info']['title'])->toBeString()
        ->and($document['info']['version'])->toBeString();
});

it('documents every implemented api route and no other', function (): void {
    $implemented = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->uri(), 'api/'))
        ->flatMap(fn ($route): array => collect($route->methods())
            // HEAD is synthesised by the router for every GET and is not a
            // separately implemented operation.
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method): string => strtolower($method).' /'.$route->uri())
            ->all())
        ->sort()
        ->values()
        ->all();

    /** @var array<string, array<string, mixed>> $paths */
    $paths = openApiDocument()['paths'];

    $documented = collect($paths)
        ->flatMap(fn (array $operations, string $path): array => collect(array_keys($operations))
            ->map(fn (string $method): string => $method.' '.$path)
            ->all())
        ->sort()
        ->values()
        ->all();

    expect($documented)->toBe($implemented)
        ->and($implemented)->toBe(['get /api/payments/{paymentId}', 'post /api/payments']);
});

it('documents the accepted initiation body and Location header exactly as sent', function (): void {
    $response = Initiation::send();

    $response->assertStatus(202);

    expect(array_keys((array) $response->json()))
        ->toBe(openApiBodyProperties('/api/payments', 'post', 202, 'application/json'))
        ->and($response->headers->get('Location'))->toBe('/api/payments/'.$response->json('payment_id'))
        ->and(openApiOperation('/api/payments', 'post')['responses']['202']['headers'])
        ->toHaveKey('Location');
});

it('documents the projected payment body in wire order', function (): void {
    $merchant = Merchant::factory()->create();
    $paymentId = (string) Str::uuid7();
    projectPayment($merchant->id, $paymentId);

    $token = Initiation::token($merchant);
    Auth::forgetGuards();

    $response = test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/payments/'.$paymentId);

    $response->assertStatus(200);

    expect(array_keys((array) $response->json()))
        ->toBe(openApiBodyProperties('/api/payments/{paymentId}', 'get', 200, 'application/json'))
        ->toBe(['payment_id', 'amount_minor', 'currency', 'status', 'initiated_at']);
});

it('documents the pending payment body and its Retry-After header', function (): void {
    $merchant = Merchant::factory()->create();

    $accepted = Initiation::send(merchant: $merchant);
    $accepted->assertStatus(202);

    /** @var string $paymentId */
    $paymentId = $accepted->json('payment_id');

    $token = Initiation::token($merchant);
    Auth::forgetGuards();

    $response = test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/payments/'.$paymentId);

    $response->assertStatus(202);

    expect(array_keys((array) $response->json()))
        ->toBe(openApiBodyProperties('/api/payments/{paymentId}', 'get', 202, 'application/json'))
        ->and($response->json('status'))->toBe('projection_pending')
        ->and($response->headers->get('Retry-After'))->toBe('1')
        ->and(openApiOperation('/api/payments/{paymentId}', 'get')['responses']['202']['headers'])
        ->toHaveKey('Retry-After');
});

it('documents the problem document members the mapper actually emits', function (): void {
    $response = test()->getJson('/api/payments/'.Str::uuid7());

    $response->assertStatus(401);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json');

    /** @var array<string, mixed> $problem */
    $problem = openApiDocument()['components']['schemas']['Problem'];

    /** @var array<string, mixed> $properties */
    $properties = $problem['properties'];

    expect(array_keys($properties))
        ->toBe(['type', 'title', 'status', 'detail', 'instance', 'code', 'errors'])
        ->and($problem['required'])->toBe(['type', 'title', 'status', 'detail', 'instance', 'code'])
        ->and(array_keys((array) $response->json()))->toBe(['type', 'title', 'status', 'detail', 'instance', 'code']);
});

it('enumerates every ProblemType code, and derives every documented example from it', function (): void {
    /** @var array<string, mixed> $problem */
    $problem = openApiDocument()['components']['schemas']['Problem'];

    /** @var list<string> $codes */
    $codes = $problem['properties']['code']['enum'];

    expect($codes)->toBe(array_map(
        static fn (ProblemType $type): string => $type->value,
        ProblemType::cases(),
    ));

    $examples = collect(openApiDocument()['paths'])
        ->flatMap(fn (array $operations): array => collect($operations)
            ->flatMap(fn (array $operation): array => collect($operation['responses'])
                ->flatMap(function (array $response): array {
                    // $ref first: the shared 401 carries no inline `content`, so
                    // reading `content` directly skips it.
                    if (isset($response['$ref'])) {
                        $name = (string) Str::afterLast((string) $response['$ref'], '/');
                        /** @var array<string, mixed> $response */
                        $response = openApiDocument()['components']['responses'][$name];
                    }

                    $content = $response['content']['application/problem+json'] ?? null;

                    if ($content === null) {
                        return [];
                    }

                    return isset($content['example'])
                        ? [$content['example']]
                        : array_column($content['examples'], 'value');
                })
                ->all())
            ->all())
        ->all();

    $covered = collect($examples)->pluck('code')->unique()->sort()->values()->all();

    expect($covered)->toBe([
        'idempotency_contention',
        'idempotency_payload_mismatch',
        'payment_not_found',
        'projection_rebuilding',
        'unauthenticated',
        'validation_failed',
    ]);

    foreach ($examples as $example) {
        $type = ProblemType::from((string) $example['code']);

        expect($example['type'])->toBe($type->type())
            ->and($example['title'])->toBe($type->title())
            ->and($example['status'])->toBe($type->status())
            ->and($example['detail'])->toBe($type->detail());
    }
});

it('refuses both operations unauthenticated, exactly as the shared 401 documents it', function (): void {
    /** @var array<string, mixed> $documented */
    $documented = openApiDocument()['components']['responses']['Unauthenticated']['content']['application/problem+json']['example'];

    // A live request per operation: a documented 401 key alone stays green with
    // auth:sanctum removed from either route.
    $queryPath = '/api/payments/'.Str::uuid7();

    $unauthenticated = [
        ['/api/payments', test()->postJson('/api/payments', Initiation::body(), ['Idempotency-Key' => Initiation::KEY])],
        [$queryPath, test()->getJson($queryPath)],
    ];

    foreach ($unauthenticated as [$path, $response]) {
        $response->assertStatus(401);

        expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and((array) $response->json())->toBe([...$documented, 'instance' => $path]);
    }

    foreach ([['/api/payments', 'post'], ['/api/payments/{paymentId}', 'get']] as [$documentedPath, $method]) {
        expect(openApiOperation($documentedPath, $method)['responses']['401'])
            ->toBe(['$ref' => '#/components/responses/Unauthenticated']);
    }
});

it('answers an unknown payment exactly as the documented 404 says', function (): void {
    $merchant = Merchant::factory()->create();
    $token = Initiation::token($merchant);
    Auth::forgetGuards();

    $paymentId = (string) Str::uuid7();

    $response = test()
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/payments/'.$paymentId);

    $response->assertStatus(404);

    /** @var array<string, mixed> $documented */
    $documented = openApiOperation('/api/payments/{paymentId}', 'get')['responses']['404']['content']['application/problem+json']['example'];

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and((array) $response->json())->toBe([...$documented, 'instance' => '/api/payments/'.$paymentId]);

    $response->assertHeaderMissing('Retry-After');
});

it('documents the idempotency key bounds the request object enforces', function (): void {
    /** @var array<string, mixed> $parameter */
    $parameter = openApiDocument()['components']['parameters']['IdempotencyKey'];

    expect($parameter['name'])->toBe('Idempotency-Key')
        ->and($parameter['required'])->toBeTrue()
        ->and($parameter['schema']['minLength'])->toBe(1)
        ->and($parameter['schema']['maxLength'])->toBe(255);

    Initiation::send(headers: ['Idempotency-Key' => str_repeat('k', 255)])->assertStatus(202);

    Auth::forgetGuards();
    Initiation::send(headers: ['Idempotency-Key' => str_repeat('k', 256)])->assertStatus(422);
});

it('documents the amount and currency bounds the request object enforces', function (): void {
    /** @var array<string, mixed> $schema */
    $schema = openApiDocument()['components']['schemas']['InitiatePaymentRequest'];

    expect($schema['required'])->toBe(['amount_minor', 'currency'])
        ->and($schema['properties']['amount_minor']['minimum'])->toBe(1)
        ->and($schema['properties']['amount_minor']['maximum'])->toBe(100_000_000);

    Initiation::send(['amount_minor' => 100_000_001])->assertStatus(422);
});

it('replays a mismatched idempotency key exactly as the documented 409 says', function (): void {
    $merchant = Merchant::factory()->create();

    Initiation::send(merchant: $merchant)->assertStatus(202);

    $response = Initiation::send(['amount_minor' => 9_900], merchant: $merchant);

    $response->assertStatus(409);

    /** @var array<string, mixed> $documented */
    $documented = openApiOperation('/api/payments', 'post')['responses']['409']['content']['application/problem+json']['example'];

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and((array) $response->json())->toBe($documented);
});

it('refuses an unsupported currency exactly as the documented 422 says', function (): void {
    $response = Initiation::send(['currency' => 'EUR']);

    $response->assertStatus(422);

    /** @var array<string, mixed> $documented */
    $documented = openApiOperation('/api/payments', 'post')['responses']['422']['content']['application/problem+json']['example'];

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and((array) $response->json())->toBe($documented);
});

it('constrains currency to the case variants the request object normalises to USD', function (): void {
    /** @var array<string, mixed> $currency */
    $currency = openApiDocument()['components']['schemas']['InitiatePaymentRequest']['properties']['currency'];

    $pattern = '/'.$currency['pattern'].'/';

    foreach (['USD', 'usd', 'Usd', 'uSd'] as $accepted) {
        expect((bool) preg_match($pattern, $accepted))->toBeTrue();

        Auth::forgetGuards();
        Initiation::send(['currency' => $accepted], headers: ['Idempotency-Key' => 'currency-'.$accepted])
            ->assertStatus(202);
    }

    foreach (['EUR', 'USDD', 'US', 'U SD', ''] as $rejected) {
        expect((bool) preg_match($pattern, $rejected))->toBeFalse();

        Auth::forgetGuards();
        Initiation::send(['currency' => $rejected], headers: ['Idempotency-Key' => 'currency-x-'.$rejected])
            ->assertStatus(422);
    }
});
