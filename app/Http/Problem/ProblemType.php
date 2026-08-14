<?php

declare(strict_types=1);

namespace App\Http\Problem;

/**
 * The stable machine identifiers this API promises.
 *
 * `code` IS THE CONTRACT. `type` is derived from it, and `title`, `status` and
 * `detail` hang off it, so a client that matches on one member can never disagree
 * with a client that matches on another.
 *
 * WHY `type` IS A RELATIVE URI REFERENCE: RFC 9457 permits one, and it is resolved
 * against the request URI. This project has no published documentation host, and a
 * dead absolute URL in a public repository is worse than a relative one. Re-basing
 * later changes this one method and nothing else.
 *
 * `detail` here is the CLIENT-SAFE sentence and the only one a deployed
 * environment ever emits. An exception message routinely carries SQL, table names
 * and identifiers; ProblemDetailsMapper decides whether debug may substitute it,
 * and the default is silence.
 *
 * TWO CASES HAVE NO PRODUCER IN SLICE 1. IdempotencyPayloadMismatch and
 * IdempotencyContention are named by
 * docs/specifications/payments/initiation-and-idempotency.md as part of this
 * slice's published error vocabulary, and Slice 2 raises them. They are data, not
 * behaviour, and keeping the vocabulary in one file means Slice 2 reopens nothing.
 * A green Slice 1 run does NOT exercise them.
 *
 * `projection_rebuilding` and `payment_not_found` are deliberately absent: they
 * belong to T9's query endpoint, and a case with no owner is a promise nothing
 * keeps.
 */
enum ProblemType: string
{
    case Unauthenticated = 'unauthenticated';
    case ValidationFailed = 'validation_failed';
    case IdempotencyPayloadMismatch = 'idempotency_payload_mismatch';
    case IdempotencyContention = 'idempotency_contention';
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
            self::NotFound => 'No API resource matches this path.',
            self::MethodNotAllowed => 'This method is not supported for this path.',
            self::InternalError => 'The request could not be completed.',
        };
    }
}
