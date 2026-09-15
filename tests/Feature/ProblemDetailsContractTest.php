<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

function registerProblemProbeRoute(string $message): void
{
    Route::middleware('api')->post('/api/_problem_probe', function () use ($message): never {
        throw new RuntimeException($message);
    });
}

/**
 * @param  array<string, string|list<string>>  $headers
 */
function registerStatusProbeRoute(int $status, array $headers = []): void
{
    Route::middleware('api')->post('/api/_status_probe', function () use ($status, $headers): never {
        throw new HttpException($status, 'generic framework failure', null, $headers);
    });
}

it('answers an unknown api path with a complete problem document', function (): void {
    $response = $this->postJson('/api/does-not-exist');

    $response->assertStatus(404);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and(array_keys($response->json()))->toContain('type', 'title', 'status', 'detail', 'instance', 'code')
        ->and($response->json('code'))->toBe('not_found')
        ->and($response->json('type'))->toBe('/problems/not-found')
        ->and($response->json('status'))->toBe(404)
        ->and($response->json('instance'))->toBe('/api/does-not-exist');
});

it('answers a wrong method with problem details and the right status', function (): void {
    $response = $this->getJson('/api/payments');

    $response->assertStatus(405);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('method_not_allowed')
        ->and($response->json('status'))->toBe(405)
        // RFC 9110 requires Allow on a 405, and the rebuilt problem response drops the original headers.
        ->and($response->headers->get('Allow'))->toBe('POST');
});

it('carries a required protocol header across without copying entity headers', function (): void {
    // Laravel's own 401 path sets no WWW-Authenticate; the X- header proves an allowlist rather than a copy.
    registerStatusProbeRoute(401, [
        'WWW-Authenticate' => 'Bearer realm="api"',
        'X-Internal-Debug' => 'do-not-leak',
    ]);

    $response = $this->postJson('/api/_status_probe');

    expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="api"')
        ->and($response->headers->has('X-Internal-Debug'))->toBeFalse()
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('preserves every challenge of a repeated protocol header', function (): void {
    // A 401 may offer several challenges, and HeaderBag::get() would keep only the first.
    registerStatusProbeRoute(401, [
        'WWW-Authenticate' => ['Bearer realm="api"', 'Basic realm="api"'],
    ]);

    $response = $this->postJson('/api/_status_probe');

    expect($response->headers->all('WWW-Authenticate'))
        ->toBe(['Bearer realm="api"', 'Basic realm="api"'])
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('never dresses a generic conflict as an idempotency payload mismatch', function (): void {
    registerStatusProbeRoute(409);

    $response = $this->postJson('/api/_status_probe');

    expect($response->json('code'))->not->toBe('idempotency_payload_mismatch')
        ->and($response->json('type'))->not->toBe('/problems/idempotency-payload-mismatch');
});

it('never dresses a generic service failure as idempotency contention', function (): void {
    registerStatusProbeRoute(503);

    $response = $this->postJson('/api/_status_probe');

    expect($response->json('code'))->not->toBe('idempotency_contention')
        ->and($response->json('type'))->not->toBe('/problems/idempotency-contention')
        ->and($response->headers->get('Retry-After'))->toBeNull();
});

it('leaves non-api error responses alone', function (): void {
    $response = $this->get('/definitely-not-a-web-route');

    $response->assertStatus(404);

    expect($response->headers->get('Content-Type'))->not->toStartWith('application/problem+json');
});

it('never exposes an exception message as detail outside debug', function (): void {
    config(['app.debug' => false]);

    registerProblemProbeRoute('SQLSTATE[42P01]: relation "payment_secrets" does not exist');

    $response = $this->postJson('/api/_problem_probe');

    $response->assertStatus(500);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('internal_error')
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('payment_secrets')
        ->and($response->json())->not->toHaveKey('message')
        ->and($response->json())->not->toHaveKey('trace');
});

it('exposes the exception message as detail only when debug is on', function (): void {
    config(['app.debug' => true]);

    registerProblemProbeRoute('SQLSTATE[42P01]: relation "payment_secrets" does not exist');

    $response = $this->postJson('/api/_problem_probe');

    $response->assertStatus(500);

    expect($response->json('detail'))->toContain('payment_secrets')
        ->and($response->json('code'))->toBe('internal_error');
});
