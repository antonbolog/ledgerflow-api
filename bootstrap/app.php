<?php

declare(strict_types=1);

use App\Http\Problem\ProblemDetailsMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Payments\Application\Query\PaymentNotFound;
use Symfony\Component\HttpFoundation\Response;

// NOTE: this file has no namespace, so `use Throwable;` would be a no-op that PHP
// warns about. Global names are referenced directly here.

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // A 404 IS NOT AN INCIDENT. Laravel already declines to report the
        // framework's own NotFoundHttpException, and PaymentNotFound is the same
        // answer for a payment rather than a route - so leaving it reportable
        // would make T9's endpoint the one 404 in the API that writes an
        // error-level line with a stack trace, once per request, at whatever rate
        // a client chooses to ask for identifiers it does not own.
        //
        // Scoped to this one type. IdempotencyPayloadMismatch and
        // IdempotencyContention stay reportable on purpose: they are rare and
        // each one is worth a look.
        $exceptions->dontReport(PaymentNotFound::class);

        // ONE hook. respond() is the only callback Laravel runs on EVERY render
        // path, including exceptions that carry their own render() method, so it
        // is the only place the "every /api/* error is problem+json" invariant can
        // be enforced. Its signature is fixed by the framework as
        // (Response, Throwable, Request).
        //
        // The mapper is built per rendered error rather than captured once: the
        // debug flag is read at render time, so nothing can go stale between
        // handler resolution and the response. One tiny readonly object on an
        // error path is not a cost worth a staleness bug.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request): Response => new ProblemDetailsMapper(
                exposeInternalDetail: (bool) config('app.debug'),
            )->finalise($response, $e, $request),
        );
    })->create();
