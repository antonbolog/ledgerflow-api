<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payments\Application\Initiation\PaymentIntentDeadline;
use Override;

final class InitiatePaymentRequest extends FormRequest
{
    private const int AMOUNT_MINOR_MIN = 1;

    private const int AMOUNT_MINOR_MAX = 100_000_000;

    private const int KEY_MIN_BYTES = 1;

    private const int KEY_MAX_BYTES = 255;

    // No #[Override]: FormRequest declares no authorize(), so the attribute would be a fatal error.
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', $this->byteLengthRule()],
            // Null after prepareForValidation() whenever the header is missing, repeated or not in canonical form.
            'intent_id' => ['required', 'string'],
            'intent_expires_at' => ['required', 'string', $this->expiryCapRule()],
            'amount_minor' => [
                'required',
                'integer',
                'min:'.self::AMOUNT_MINOR_MIN,
                'max:'.self::AMOUNT_MINOR_MAX,
                $this->jsonIntegerRule(),
            ],
            'currency' => ['required', 'string', Rule::in(['USD'])],
            // `missing`, not `prohibited`: prohibited accepts an explicit null.
            'merchant_id' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    #[Override]
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'intent_id.required' => 'The Ledgerflow-Intent-Id header must be exactly one lowercase canonical UUID.',
            'intent_expires_at.required' => 'The Ledgerflow-Intent-Expires-At header must be exactly one UTC timestamp in YYYY-MM-DDTHH:MM:SS.sssZ form.',
            'currency.in' => 'The currency must be USD.',
            'merchant_id.missing' => 'The merchant is resolved from the authenticated user and cannot be supplied.',
        ];
    }

    public function canonicalAmountMinor(): int
    {
        return $this->integer('amount_minor');
    }

    public function canonicalCurrency(): string
    {
        return $this->string('currency')->toString();
    }

    // Byte-identical: any normalisation would change which requests count as the same one.
    public function rawIdempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }

    public function intentId(): string
    {
        return $this->string('intent_id')->toString();
    }

    // The header's own bytes, already proven to be one canonical UTC timestamp: PostgreSQL parses it as given.
    public function intentExpiresAt(): string
    {
        return $this->string('intent_expires_at')->toString();
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        // all(), not header(): header() returns only the first value, which would hide a repeated header.
        $expiresAt = $this->headers->all(PaymentIntentHeaders::EXPIRES_AT);

        $merge = [
            'idempotency_key' => $this->header('Idempotency-Key'),
            'intent_id' => PaymentIntentHeaders::intentId($this->headers->all(PaymentIntentHeaders::INTENT_ID)),
            'intent_expires_at' => PaymentIntentHeaders::expiresAt($expiresAt) === null ? null : $expiresAt[0],
        ];

        $currency = $this->input('currency');

        if (is_string($currency)) {
            $merge['currency'] = strtoupper($currency);
        }

        $this->merge($merge);
    }

    // PostgreSQL time, never the PHP clock, decides how far ahead the expiry may be.
    private function expiryCapRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && resolve(PaymentIntentDeadline::class)->exceedsCap($value)) {
                $fail('The Ledgerflow-Intent-Expires-At header is further ahead than the API accepts.');
            }
        };
    }

    // Laravel's `integer` rule also accepts numeric strings such as "1250".
    private function jsonIntegerRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('The :attribute field must be a JSON integer.');
            }
        };
    }

    // strlen(), not mb_strlen(): the key limit is bytes.
    private function byteLengthRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || strlen($value) < self::KEY_MIN_BYTES || strlen($value) > self::KEY_MAX_BYTES) {
                $fail(sprintf(
                    'The Idempotency-Key header must be between %d and %d bytes.',
                    self::KEY_MIN_BYTES,
                    self::KEY_MAX_BYTES,
                ));
            }
        };
    }
}
