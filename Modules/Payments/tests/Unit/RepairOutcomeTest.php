<?php

declare(strict_types=1);

use Modules\Payments\Application\Repair\RepairMutationState;
use Modules\Payments\Application\Repair\RepairOutcome;
use Modules\Payments\Application\Repair\RepairReason;

it('separates a refusal from a failure, which is what the exit codes carry', function (): void {
    expect(RepairReason::CanonicalEventAbsent->isRefusal())->toBeTrue()
        ->and(RepairReason::CanonicalEventAmbiguous->isRefusal())->toBeTrue()
        ->and(RepairReason::AggregateIdentityConflict->isRefusal())->toBeTrue()
        ->and(RepairReason::StatementTimeout->isRefusal())->toBeFalse()
        ->and(RepairReason::DatabaseError->isRefusal())->toBeFalse()
        ->and(RepairReason::CommitOutcomeUnknown->isRefusal())->toBeFalse()
        ->and(RepairReason::AuditFailed->isRefusal())->toBeFalse()
        ->and(RepairReason::IntentAuditFailed->isRefusal())->toBeFalse();
});

it('exits 2 for a lookup timeout and reports the transaction outcome it was given', function (
    RepairMutationState $state,
): void {
    $outcome = RepairOutcome::failed(RepairReason::StatementTimeout, $state, '0199a3c1-1111-7000-8000-000000000001');

    expect($outcome->exitCode())->toBe(RepairOutcome::EXIT_FAILED)
        ->and($outcome->outcome())->toBe(RepairOutcome::OUTCOME_FAILED)
        ->and($outcome->mutationState)->toBe($state);
})->with([
    'confirmed rollback' => [RepairMutationState::RolledBack],
    'unconfirmed rollback' => [RepairMutationState::Unknown],
]);

it('exits 1 only for a refusal, and reports nothing written', function (): void {
    $outcome = RepairOutcome::refused(RepairReason::CanonicalEventAbsent, '0199a3c1-1111-7000-8000-000000000001', true);

    expect($outcome->exitCode())->toBe(RepairOutcome::EXIT_REFUSED)
        ->and($outcome->outcome())->toBe(RepairOutcome::OUTCOME_REFUSED)
        ->and($outcome->mutationState)->toBe(RepairMutationState::None)
        ->and($outcome->changedFields)->toBe([])
        ->and($outcome->historyReinitialized)->toBeFalse();
});

it('exits 0 only when the repair reached a known end', function (
    RepairMutationState $state,
    string $expected,
): void {
    $outcome = RepairOutcome::completed($state, '0199a3c1-1111-7000-8000-000000000001', [], false);

    expect($outcome->exitCode())->toBe(RepairOutcome::EXIT_DONE)
        ->and($outcome->outcome())->toBe($expected);
})->with([
    'created' => [RepairMutationState::Created, RepairOutcome::OUTCOME_CREATED],
    'repaired' => [RepairMutationState::Repaired, RepairOutcome::OUTCOME_REPAIRED],
    'unchanged' => [RepairMutationState::NoChanges, RepairOutcome::OUTCOME_UNCHANGED],
]);

it('exits 2 when the work finished and its record did not', function (): void {
    $outcome = RepairOutcome::failed(
        RepairReason::AuditFailed,
        RepairMutationState::Committed,
        '0199a3c1-1111-7000-8000-000000000001',
        ['payload'],
    );

    expect($outcome->exitCode())->toBe(RepairOutcome::EXIT_FAILED)
        ->and($outcome->mutationState)->toBe(RepairMutationState::Committed)
        ->and($outcome->changedFields)->toBe(['payload']);
});
