<?php

declare(strict_types=1);

use App\Http\Problem\ProblemDetailsMapper;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Payments\Application\Query\PaymentNotFound;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Clients choose which identifiers to request, so this 404 must not write error-level reports.
        $exceptions->dontReport(PaymentNotFound::class);

        // respond() is the only hook Laravel runs on every render path; app.debug is read per rendered error.
        $exceptions->respond(
            fn (Response $response, Throwable $e, Request $request): Response => new ProblemDetailsMapper(
                exposeInternalDetail: (bool) config('app.debug'),
            )->finalise($response, $e, $request),
        );
    })->create();
