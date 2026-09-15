<?php

declare(strict_types=1);

namespace App\Http\Problem;

enum ProblemType: string
{
    case Unauthenticated = 'unauthenticated';
    case ValidationFailed = 'validation_failed';
    case IdempotencyPayloadMismatch = 'idempotency_payload_mismatch';
    case IdempotencyContention = 'idempotency_contention';
    case PaymentNotFound = 'payment_not_found';
    case ProjectionRebuilding = 'projection_rebuilding';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case InternalError = 'internal_error';

    public function type(): string
    {
        return '/problems/'.str_replace('_', '-', $this->value);
    }

    public function title(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Unauthenticated',
            self::ValidationFailed => 'Validation failed',
            self::IdempotencyPayloadMismatch => 'Idempotency key reused with a different payload',
            self::IdempotencyContention => 'Idempotency contention',
            self::PaymentNotFound => 'Payment not found',
            self::ProjectionRebuilding => 'Projection rebuilding',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::InternalError => 'Internal error',
        };
    }

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::ValidationFailed => 422,
            self::IdempotencyPayloadMismatch => 409,
            self::IdempotencyContention => 503,
            self::PaymentNotFound => 404,
            self::ProjectionRebuilding => 503,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InternalError => 500,
        };
    }

    public function detail(): string
    {
        return match ($this) {
            self::Unauthenticated => 'This endpoint requires an authenticated merchant user.',
            self::ValidationFailed => 'The request payload did not satisfy the initiation contract.',
            self::IdempotencyPayloadMismatch => 'This idempotency key was already used with a different payload.',
            self::IdempotencyContention => 'The request could not be arbitrated in time. Retry.',
            self::PaymentNotFound => 'No payment matching this identifier is available.',
            self::ProjectionRebuilding => 'A projection rebuild is in progress. Retry.',
            self::NotFound => 'No API resource matches this path.',
            self::MethodNotAllowed => 'This method is not supported for this path.',
            self::InternalError => 'The request could not be completed.',
        };
    }
}
