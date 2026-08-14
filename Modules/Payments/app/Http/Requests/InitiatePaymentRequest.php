<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

/**
 * The user-facing validation boundary for POST /api/payments.
 *
 * PaymentInitiated's constructor validates the same values as a last line of
 * defence. If one of its exceptions ever reaches the HTTP path, this class let
 * something through.
 *
 * THE IDEMPOTENCY KEY IS A HEADER, and validation runs over input, so
 * prepareForValidation() merges it in under a snake_case name. That name is what
 * the problem document reports in `errors`, which is why it is `idempotency_key`
 * and not `Idempotency-Key`.
 *
 * LENGTH IS MEASURED IN BYTES with strlen(), never in characters with mb_strlen:
 * the specification says 1-255 bytes, and a multibyte key would satisfy a
 * character check while overflowing the intent — and the column that will store
 * its digest.
 *
 * merchant_id MUST BE ABSENT, NOT MERELY EMPTY. Silently dropping it would let a
 * client believe it selected an owner and be quietly overruled. The merchant comes
 * from the authenticated user, and nowhere else.
 *
 * THE RULE IS `missing`, NOT `prohibited`. Laravel's validateProhibited() is
 * literally `! validateRequired()`, and validateRequired() counts null and '' as
 * absent — so `prohibited` ACCEPTS an explicitly supplied `"merchant_id": null`.
 * validateMissing() is `! Arr::has($this->data, $attribute)`, which is the
 * true-absence the contract means.
 */
final class InitiatePaymentRequest extends FormRequest
{
    private const int AMOUNT_MINOR_MIN = 1;

    private const int AMOUNT_MINOR_MAX = 100_000_000;

    private const int KEY_MIN_BYTES = 1;

    private const int KEY_MAX_BYTES = 255;

    // No #[Override]: FormRequest declares no authorize(), it is discovered by
    // method_exists. The attribute would be a fatal error.
    public function authorize(): bool
    {
        // The route's auth:sanctum middleware owns authentication. Returning false
        // here would produce a 403 the contract does not define.
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', $this->byteLengthRule()],
            'amount_minor' => [
                'required',
                'integer',
                'min:'.self::AMOUNT_MINOR_MIN,
                'max:'.self::AMOUNT_MINOR_MAX,
                $this->jsonIntegerRule(),
            ],
            // Canonicalised to upper case in prepareForValidation, so the rule stays
            // a single literal and 'usd', 'Usd' and 'USD' cannot diverge.
            'currency' => ['required', 'string', Rule::in(['USD'])],
            'merchant_id' => ['missing'],
        ];
    }

    /** @return array<string, string> */
    #[Override]
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'currency.in' => 'The currency must be USD.',
            'merchant_id.missing' => 'The merchant is resolved from the authenticated user and cannot be supplied.',
        ];
    }

    /**
     * The canonical command inputs.
     *
     * Slice 2 builds the immutable PaymentInitiationContext from exactly these
     * three, which is why they are typed rather than left as validated() lookups:
     * the context's constructor takes int, string and string, and a mixed at that
     * boundary would push the narrowing into the use case.
     *
     * They read canonicalised input, so canonicalCurrency() returns what
     * prepareForValidation() produced rather than upper-casing on read. The raw
     * idempotency key is returned BYTE-IDENTICAL: it is hashed, never transformed,
     * and any normalisation here would change which requests count as the same one.
     */
    public function canonicalAmountMinor(): int
    {
        return $this->integer('amount_minor');
    }

    public function canonicalCurrency(): string
    {
        return $this->string('currency')->toString();
    }

    public function rawIdempotencyKey(): string
    {
        return $this->string('idempotency_key')->toString();
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $merge = ['idempotency_key' => $this->header('Idempotency-Key')];

        $currency = $this->input('currency');

        if (is_string($currency)) {
            $merge['currency'] = strtoupper($currency);
        }

        $this->merge($merge);
    }

    /**
     * Laravel's `integer` rule accepts a numeric STRING, so "1250" would pass it.
     * The contract says integer, and in a payments API a client type bug is worth
     * surfacing rather than absorbing. Checked against the decoded JSON value, so
     * the distinction survives.
     */
    private function jsonIntegerRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail('The :attribute field must be a JSON integer.');
            }
        };
    }

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
