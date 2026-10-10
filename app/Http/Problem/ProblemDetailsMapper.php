<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Application\Initiation\IdempotencyPayloadMismatch;
use Modules\Payments\Application\Initiation\PaymentIntentDeadlineRefused;
use Modules\Payments\Application\Initiation\PaymentIntentSessionTerminated;
use Modules\Payments\Application\Initiation\ProjectionRebuilding as InitiationProjectionRebuilding;
use Modules\Payments\Application\Query\PaymentNotFound;
use Modules\Payments\Application\Query\ProjectionRebuilding as QueryProjectionRebuilding;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class ProblemDetailsMapper
{
    private const array PRESERVED_HEADERS = ['Allow', 'WWW-Authenticate'];

    public function __construct(private bool $exposeInternalDetail) {}

    public function finalise(Response $response, Throwable $e, Request $request): Response
    {
        if (! $request->is('api/*') || $response->getStatusCode() < 400) {
            return $response;
        }

        $problem = $this->classify($response, $e);
        $errors = [];

        if ($e instanceof ValidationException) {
            // errors() is annotated upstream as a bare array; it is MessageBag::messages().
            /** @var array<string, array<int, string>> $errors */
            $errors = $e->errors();
        }

        if ($problem === ProblemType::ValidationFailed && $e instanceof PaymentIntentDeadlineRefused) {
            $errors = ['intent_expires_at' => ['The payment intent expired before it could create a payment.']];
        }

        $document = new ProblemDetails(
            $problem,
            $this->detailFor($problem, $e),
            $request->getPathInfo(),
            $errors,
            $this->retryAfterFor($problem, $e),
        )->toResponse();

        foreach (self::PRESERVED_HEADERS as $header) {
            // all(), not get(): WWW-Authenticate may repeat, one field per challenge.
            $values = array_values(array_filter(
                $response->headers->all($header),
                static fn (?string $value): bool => $value !== null,
            ));

            if ($values !== []) {
                $document->headers->set($header, $values);
            }
        }

        return $document;
    }

    private function classify(Response $response, Throwable $e): ProblemType
    {
        return match (true) {
            $e instanceof AuthenticationException => ProblemType::Unauthenticated,
            $e instanceof ValidationException => ProblemType::ValidationFailed,

            // Exact types before the status fallback: Payments application exceptions
            // cannot implement HttpExceptionInterface and would otherwise render as 500.
            $e instanceof IdempotencyPayloadMismatch => ProblemType::IdempotencyPayloadMismatch,
            $e instanceof IdempotencyContention => ProblemType::IdempotencyContention,

            // Unsettled until the expiry plus the residual allowance: a concurrent request for the same intent may still
            // commit, so only a refusal after that point may settle the intent as rejected (ADR 0008).
            $e instanceof PaymentIntentDeadlineRefused => $e->millisecondsUntilSettled > 0
                ? ProblemType::IdempotencyContention
                : ProblemType::ValidationFailed,
            $e instanceof PaymentIntentSessionTerminated => ProblemType::IdempotencyContention,
            $e instanceof InitiationProjectionRebuilding, $e instanceof QueryProjectionRebuilding => ProblemType::ProjectionRebuilding,
            $e instanceof PaymentNotFound => ProblemType::PaymentNotFound,

            // No 409 or 503 arm: those Payments codes are reachable only through the exception types above.
            default => match ($response->getStatusCode()) {
                401 => ProblemType::Unauthenticated,
                404 => ProblemType::NotFound,
                405 => ProblemType::MethodNotAllowed,
                422 => ProblemType::ValidationFailed,
                default => ProblemType::InternalError,
            },
        };
    }

    private function retryAfterFor(ProblemType $problem, Throwable $e): ?int
    {
        if ($problem === ProblemType::IdempotencyContention && $e instanceof PaymentIntentDeadlineRefused) {
            return max(1, (int) ceil($e->millisecondsUntilSettled / 1000));
        }

        // A deadline-terminated session ends at the expiry, so the residual allowance left until it settles is under one
        // second and the minimum of 1 already covers it.
        return in_array($problem, [ProblemType::IdempotencyContention, ProblemType::ProjectionRebuilding], true) ? 1 : null;
    }

    private function detailFor(ProblemType $problem, Throwable $e): string
    {
        if ($problem === ProblemType::InternalError && $this->exposeInternalDetail) {
            return $e->getMessage();
        }

        return $problem->detail();
    }
}
