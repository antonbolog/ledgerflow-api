<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
|--------------------------------------------------------------------------
| RFC 9457 Problem Details — the API-wide invariant
|--------------------------------------------------------------------------
|
| One rule, asserted here rather than per endpoint: NO /api/* error response
| leaves with a content type other than application/problem+json, and no web
| response is reshaped.
|
| These are properties of the whole API surface, not of Payments, which is why
| this file lives in tests/Feature rather than in a module. The mapper it covers
| lives in app/Http/Problem for the same reason.
|
| WHY A HOOK AND NOT PER-CONTROLLER HANDLING: Laravel's exception handler renders
| an error OUTSIDE the middleware stack, so middleware cannot see it. Only
| $exceptions->respond() runs on every render path — including exceptions that
| carry their own render() method — which is what makes the invariant above
| enforceable in one place.
|
*/

/** A route that exists only to make an unmapped 500 reachable without production code. */
function registerProblemProbeRoute(string $message): void
{
    Route::middleware('api')->post('/api/_problem_probe', function () use ($message): never {
        throw new RuntimeException($message);
    });
}

/**
 * A route that raises a generic framework HttpException with a chosen status and
 * headers. This is how a status the API has NOT named reaches the mapper.
 *
 * A header value may be a LIST, because HeaderBag::set() accepts one, and
 * WWW-Authenticate is the header that routinely carries several.
 *
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
    // ProblemType::MethodNotAllowed exists; an enum case with no test is a promise
    // nothing keeps. This also covers the mapper's status-code fallback, which is
    // the branch that handles every framework HttpException the type match does not
    // name.
    $response = $this->getJson('/api/payments');

    $response->assertStatus(405);

    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('method_not_allowed')
        ->and($response->json('status'))->toBe(405)
        // RFC 9110 makes Allow REQUIRED on a 405. Building a fresh problem response
        // throws away the original's headers, so the media type must be re-set and
        // the protocol headers carried across deliberately. Without that, this
        // endpoint would answer 405 while telling the client nothing about which
        // method to use.
        ->and($response->headers->get('Allow'))->toBe('POST');
});

it('carries a required protocol header across without copying entity headers', function (): void {
    // WWW-Authenticate is the other header a client cannot act without. Raised
    // through a generic HttpException because Laravel's own 401 path does not set
    // it, so this proves the allowlist mechanism rather than one framework path.
    //
    // The X- header proves it is an ALLOWLIST and not a blanket copy: an internal
    // header must not ride along into a client-facing document.
    registerStatusProbeRoute(401, [
        'WWW-Authenticate' => 'Bearer realm="api"',
        'X-Internal-Debug' => 'do-not-leak',
    ]);

    $response = $this->postJson('/api/_status_probe');

    expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="api"')
        ->and($response->headers->has('X-Internal-Debug'))->toBeFalse()
        // Content-Type must be the document's own, never the original's.
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('preserves every challenge of a repeated protocol header', function (): void {
    // RFC 9110 §11.6.1 lets a 401 offer SEVERAL authentication schemes, one
    // WWW-Authenticate field per challenge, and a client is entitled to pick any of
    // them. HeaderBag::get() returns the FIRST value only, so copying with it
    // silently deletes every challenge after the first — the response stays
    // well-formed and a client that only speaks Basic is simply locked out.
    //
    // The single-challenge test above cannot see this: one value survives either
    // way. This is the mutation that separates get() from all().
    registerStatusProbeRoute(401, [
        'WWW-Authenticate' => ['Bearer realm="api"', 'Basic realm="api"'],
    ]);

    $response = $this->postJson('/api/_status_probe');

    expect($response->headers->all('WWW-Authenticate'))
        ->toBe(['Bearer realm="api"', 'Basic realm="api"'])
        // Still the document's OWN media type: preserving multiplicity must not
        // become a blanket copy of the response being replaced.
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
});

it('never dresses a generic conflict as an idempotency payload mismatch', function (): void {
    // The Payments idempotency problems are reachable ONLY through their exception
    // types, which Slice 2 introduces. A status-code arm for 409 would hand this
    // generic framework conflict a Payments code it has nothing to do with, and a
    // client matching on `code` would act on a payments condition that never
    // happened.
    registerStatusProbeRoute(409);

    $response = $this->postJson('/api/_status_probe');

    expect($response->json('code'))->not->toBe('idempotency_payload_mismatch')
        ->and($response->json('type'))->not->toBe('/problems/idempotency-payload-mismatch');
});

it('never dresses a generic service failure as idempotency contention', function (): void {
    // Same defect on the 503 arm, plus a worse consequence: Retry-After: 1 tells a
    // client this is transient contention worth retrying in one second, which a
    // generic 503 gives no basis for.
    registerStatusProbeRoute(503);

    $response = $this->postJson('/api/_status_probe');

    expect($response->json('code'))->not->toBe('idempotency_contention')
        ->and($response->json('type'))->not->toBe('/problems/idempotency-contention')
        ->and($response->headers->get('Retry-After'))->toBeNull();
});

it('leaves non-api error responses alone', function (): void {
    // The finaliser must not reshape the web surface. Without the api/* guard this
    // would silently turn every HTML error page into a JSON document.
    $response = $this->get('/definitely-not-a-web-route');

    $response->assertStatus(404);

    expect($response->headers->get('Content-Type'))->not->toStartWith('application/problem+json');
});

it('never exposes an exception message as detail outside debug', function (): void {
    config(['app.debug' => false]);

    registerProblemProbeRoute('SQLSTATE[42P01]: relation "payment_secrets" does not exist');

    $response = $this->postJson('/api/_problem_probe');

    $response->assertStatus(500);

    // The whole document, not just `detail`: a leak through `title` or a stray key
    // would be just as damaging, and an exception message routinely carries SQL,
    // table names and identifiers.
    expect($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('code'))->toBe('internal_error')
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('payment_secrets')
        ->and($response->json())->not->toHaveKey('message')
        ->and($response->json())->not->toHaveKey('trace');
});

it('exposes the exception message as detail only when debug is on', function (): void {
    // The non-vacuous control for the test above. Without it, a mapper that
    // hardcoded the safe sentence and ignored the flag entirely would pass — and
    // the "outside debug" qualifier would be describing behaviour nothing enforces.
    config(['app.debug' => true]);

    registerProblemProbeRoute('SQLSTATE[42P01]: relation "payment_secrets" does not exist');

    $response = $this->postJson('/api/_problem_probe');

    $response->assertStatus(500);

    expect($response->json('detail'))->toContain('payment_secrets')
        ->and($response->json('code'))->toBe('internal_error');
});
