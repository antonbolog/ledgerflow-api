<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Http\JsonResponse;

/**
 * One RFC 9457 problem document.
 *
 * Member order below is the wire order, deliberately: a client reading a raw body
 * sees `type` first, and a stable document is easier to diff during an incident.
 */
final readonly class ProblemDetails
{
    /** @param  array<string, array<int, string>>  $errors */
    public function __construct(
        public ProblemType $problem,
        public string $detail,
        public string $instance,
        public array $errors = [],
        public ?int $retryAfter = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $document = [
            'type' => $this->problem->type(),
            'title' => $this->problem->title(),
            'status' => $this->problem->status(),
            'detail' => $this->detail,
            'instance' => $this->instance,
            'code' => $this->problem->value,
        ];

        // Present only when it carries something. RFC 9457 extension members are
        // optional, and an always-empty `errors` would teach clients to ignore it.
        if ($this->errors !== []) {
            $document['errors'] = $this->errors;
        }

        return $document;
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse($this->toArray(), $this->problem->status());

        // Set AFTER construction, not through the constructor's headers argument.
        // Symfony's JsonResponse::update() writes application/json whenever the
        // header is absent, so the media type has to be replaced once the response
        // exists rather than merged into its birth.
        $response->headers->set('Content-Type', 'application/problem+json');

        if ($this->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
