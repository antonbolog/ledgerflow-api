<?php

declare(strict_types=1);

use Modules\Payments\Infrastructure\Verification\VerificationConfiguration;

/*
|--------------------------------------------------------------------------
| Verification configuration
|--------------------------------------------------------------------------
|
| Every bound the audit runs under is checked HERE, which in the running
| application means at container resolution — before the REPEATABLE READ snapshot
| opens. A limit discovered on the first loop of an open transaction is not a
| limit, so each invalid case below has to fail at construction rather than
| becoming a zero-valued bound at run time.
|
*/

/** @return array<string, mixed> */
function verificationConfigArray(array $overrides = []): array
{
    return array_merge([
        'batch_size' => 100,
        'example_limit' => 50,
        'grace_seconds' => 30,
        'statement_timeout_ms' => 2_000,
        'transaction_timeout_ms' => 120_000,
        'max_payload_bytes' => 65_536,
    ], $overrides);
}

it('exposes every audit bound as the typed shape the verifier consumes', function (): void {
    $config = VerificationConfiguration::fromArray(verificationConfigArray());

    expect($config->batchSize)->toBe(100)
        ->and($config->exampleLimit)->toBe(50)
        ->and($config->graceSeconds)->toBe(30)
        ->and($config->statementTimeoutMs)->toBe(2_000)
        ->and($config->transactionTimeoutMs)->toBe(120_000)
        ->and($config->maxPayloadBytes)->toBe(65_536);
});

it('renders both PostgreSQL bounds as SET LOCAL statements', function (): void {
    $config = VerificationConfiguration::fromArray(verificationConfigArray());

    expect($config->statementTimeoutStatement())->toBe('set local statement_timeout = 2000')
        ->and($config->transactionTimeoutStatement())->toBe('set local transaction_timeout = 120000');
});

it('derives the in-process work budget from the transaction bound', function (): void {
    // ONE DEADLINE, TWO ENFORCERS. A separately configured application budget
    // would be a second way for the same audit to be wrong about how long it may
    // run.
    $config = VerificationConfiguration::fromArray(verificationConfigArray(['transaction_timeout_ms' => 5_000]));

    expect($config->workBudgetNanoseconds())->toBe(5_000_000_000);
});

it('refuses a missing bound rather than defaulting it', function (string $key): void {
    $config = verificationConfigArray();
    unset($config[$key]);

    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray($config))
        ->toThrow(InvalidArgumentException::class, $key);
})->with([
    'batch_size', 'example_limit', 'grace_seconds',
    'statement_timeout_ms', 'transaction_timeout_ms', 'max_payload_bytes',
]);

it('refuses a null bound as the wrong type rather than as a missing key', function (): void {
    // array_key_exists, not isset: the two must be distinguishable, and neither
    // may be defaulted.
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['batch_size' => null]),
    ))->toThrow(InvalidArgumentException::class, 'must be an integer');
});

it('refuses a string bound, which is what an uncast env() produces', function (): void {
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['batch_size' => '100']),
    ))->toThrow(InvalidArgumentException::class, 'Check the (int) cast');
});

it('refuses a batch size outside the specified range', function (int $batchSize): void {
    // Zero reads nothing and would report a clean snapshot of it; above a thousand
    // is no longer a batch.
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['batch_size' => $batchSize]),
    ))->toThrow(InvalidArgumentException::class, 'must be between 1 and 1000');
})->with([0, -1, 1_001]);

it('accepts a zero example cap but refuses one above the specified maximum', function (): void {
    // ZERO IS COHERENT HERE AND NOWHERE ELSE: counts without retained diagnostics
    // is a thing an operator may legitimately ask for.
    expect(VerificationConfiguration::fromArray(verificationConfigArray(['example_limit' => 0]))->exampleLimit)
        ->toBe(0);

    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['example_limit' => 201]),
    ))->toThrow(InvalidArgumentException::class, 'must be between 0 and 200');
});

it('refuses a statement bound that is not strictly below the transaction bound', function (int $statementTimeout): void {
    // Equality is refused as firmly as inversion: two timers that fire together
    // race, and which one wins is decided by scheduling rather than configuration.
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray([
            'statement_timeout_ms' => $statementTimeout,
            'transaction_timeout_ms' => 2_000,
        ]),
    ))->toThrow(InvalidArgumentException::class, 'strictly below');
})->with([2_000, 2_001]);

it('refuses a zero grace, which would call every fresh delivery overdue', function (): void {
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['grace_seconds' => 0]),
    ))->toThrow(InvalidArgumentException::class, 'must be between 1 and 86400');
});

it('refuses a zero payload cap, which would make every audit incomplete', function (): void {
    expect(fn (): VerificationConfiguration => VerificationConfiguration::fromArray(
        verificationConfigArray(['max_payload_bytes' => 0]),
    ))->toThrow(InvalidArgumentException::class, 'must be between 1 and 16777216');
});
