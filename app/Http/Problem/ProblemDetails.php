<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Illuminate\Http\JsonResponse;

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

        if ($this->errors !== []) {
            $document['errors'] = $this->errors;
        }

        return $document;
    }

    public function toResponse(): JsonResponse
    {
        $response = new JsonResponse($this->toArray(), $this->problem->status());

        // Set after construction: JsonResponse::update() writes application/json when the header is absent.
        $response->headers->set('Content-Type', 'application/problem+json');

        if ($this->retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $this->retryAfter);
        }

        return $response;
    }
}
