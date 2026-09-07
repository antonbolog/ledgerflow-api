<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Payments\Application\Initiation\IdempotencyContention;
use Modules\Payments\Application\Initiation\IdempotencyPayloadMismatch;
use Modules\Payments\Application\Initiation\ProjectionRebuilding as InitiationProjectionRebuilding;
use Modules\Payments\Application\Query\PaymentNotFound;
use Modules\Payments\Application\Query\ProjectionRebuilding as QueryProjectionRebuilding;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns every /api/* error response into an RFC 9457 problem document, and leaves
 * everything else exactly as it was.
 *
 * WIRED THROUGH $exceptions->respond(), AND ONLY THERE. Laravel's Handler::render()
 * has four exits — an exception's own render(), a Responsable, a renderable
 * callback, and a final match — but every one of them passes its result through
 * finalizeRenderedResponse(), which is what respond() registers. It is therefore
 * the single place where "no API error escapes in another shape" is enforceable.
 * A middleware cannot do this: the handler renders OUTSIDE the middleware stack,
 * so a middleware never sees an exception-derived response at all.
 *
 * The callback signature is fixed by the framework as
 * (Response $response, Throwable $e, Request $request) — see
 * Handler::finalizeRenderedResponse(). The order is not adjustable and, with
 * untyped parameters, getting it wrong fails silently.
 *
 * TYPE FIRST, STATUS SECOND, AND THAT ORDER IS LOAD-BEARING. prepareJsonResponse()
 * gives any non-HttpException a 500, and the Payments application exceptions Slice
 * 2 adds cannot implement Symfony's HttpExceptionInterface — the module boundary
 * rule forbids an Application class from naming any external namespaced type. A
 * status-code-driven mapper would therefore render a 409 as a 500.
 */
final readonly class ProblemDetailsMapper
{
    /**
     * Headers the replacement document must inherit from the response it replaces.
     *
     * Building a fresh problem response throws away everything the framework set,
     * and some of what it set is REQUIRED by the protocol: RFC 9110 makes `Allow`
     * mandatory on a 405, and `WWW-Authenticate` is what tells a 401 client how to
     * authenticate. Losing them turns a correct status into an unactionable one.
     *
     * AN ALLOWLIST, NOT A BLANKET COPY. Entity headers describe the body, and the
     * body is now a different document: `Content-Type` is the problem media type
     * and `Content-Length` is recomputed on send, so copying either would describe
     * the response that no longer exists. Anything else the framework attached —
     * internal debug headers included — has no business in a client-facing
     * document.
     *
     * @var list<string>
     */
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
            // ValidationException::errors() is annotated upstream as a bare `array`,
            // so level 10 cannot see its shape. It is MessageBag::messages(), which
            // is field => list of message strings. Narrowed here rather than by
            // weakening ProblemDetails, whose type is this API's own contract.
            /** @var array<string, array<int, string>> $errors */
            $errors = $e->errors();
        }

        $document = new ProblemDetails(
            $problem,
            $this->detailFor($problem, $e),
            $request->getPathInfo(),
            $errors,
            in_array($problem, [ProblemType::IdempotencyContention, ProblemType::ProjectionRebuilding], true) ? 1 : null,
        )->toResponse();

        foreach (self::PRESERVED_HEADERS as $header) {
            // all(), NEVER get(). HeaderBag::get() returns the FIRST value only, and
            // both allowlisted headers may legitimately repeat: RFC 9110 §11.6.1
            // gives a 401 one WWW-Authenticate field per challenge, and a client is
            // entitled to pick any of them. Copying with get() would leave a
            // well-formed response that silently locks out every client whose scheme
            // was not listed first.
            // all() is typed list<string|null>, because HeaderBag::set($k, null)
            // stores a null entry. Those are dropped rather than forwarded: get()
            // returned null for them before, so they were already never copied, and
            // set() will not take them.
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

            // EXACT TYPES, ABOVE THE STATUS FALLBACK. These two live in
            // Modules\Payments\Application, where the module boundary rule forbids
            // naming any external namespaced type — so they cannot implement
            // Symfony's HttpExceptionInterface and prepareJsonResponse() renders
            // them as a 500. Only the type tells us what they are, and only these
            // types may produce these codes: a generic framework 409 is not a
            // payload mismatch, and a generic 503 is not idempotency contention.
            $e instanceof IdempotencyPayloadMismatch => ProblemType::IdempotencyPayloadMismatch,
            $e instanceof IdempotencyContention => ProblemType::IdempotencyContention,

            // ONE PROBLEM TYPE, TWO EXCEPTION TYPES. T11's shared projection gate is
            // checked from two different Infrastructure adapters - the write path's
            // RetryingAtomicTransaction and the read path's DatabasePaymentQuery - and
            // each raises its own context's exception rather than importing the
            // other's, exactly as IdempotencyContention and PaymentNotFound already do
            // for their own contexts. Both render as the one 503.
            $e instanceof InitiationProjectionRebuilding, $e instanceof QueryProjectionRebuilding => ProblemType::ProjectionRebuilding,

            // ABOVE THE 404 FALLBACK ON PURPOSE. A generic framework 404 means "no
            // such route"; this one means "no such payment for this merchant", and
            // the query endpoint is the only thing that may say so. Matching by
            // status instead would hand payment_not_found to every unmatched
            // /api/* path.
            $e instanceof PaymentNotFound => ProblemType::PaymentNotFound,

            // Only now the framework's own HttpExceptions, whose status IS their
            // meaning.
            //
            // THERE IS NO 409 OR 503 ARM HERE, DELIBERATELY. The two Payments
            // idempotency problems are domain conclusions, not status codes: a
            // generic framework 409 is not a payload mismatch, and a generic 503 is
            // not idempotency contention. A status arm would hand an unrelated
            // failure a Payments code, and in the 503 case a Retry-After: 1 that
            // tells a client to retry something nothing said was transient. They
            // are reachable only through their exception types, which Slice 2 adds
            // above this fallback.
            default => match ($response->getStatusCode()) {
                401 => ProblemType::Unauthenticated,
                404 => ProblemType::NotFound,
                405 => ProblemType::MethodNotAllowed,
                422 => ProblemType::ValidationFailed,
                default => ProblemType::InternalError,
            },
        };
    }

    /**
     * The safe sentence, always, unless debug is on AND the failure was unmapped.
     *
     * Only InternalError may ever carry an exception message: every other type is a
     * condition this API named on purpose, and its own sentence is more useful to a
     * client than the framework's phrasing.
     */
    private function detailFor(ProblemType $problem, Throwable $e): string
    {
        if ($problem === ProblemType::InternalError && $this->exposeInternalDetail) {
            return $e->getMessage();
        }

        return $problem->detail();
    }
}
