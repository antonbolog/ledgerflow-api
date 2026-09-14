<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payments\Infrastructure\Projection\ProjectionGate;
use Modules\Payments\Infrastructure\Projection\ProjectionGateState;
use Modules\Payments\Tests\Integration\IntegrationDatabase;

beforeEach(function (): void {
    IntegrationDatabase::ensureSchema();
    IntegrationDatabase::register();
    IntegrationDatabase::refuseIfWrapped();
    IntegrationDatabase::truncate();
});

afterEach(function (): void {
    IntegrationDatabase::rollbackOpenTransactions();
    IntegrationDatabase::releaseLocks();
    IntegrationDatabase::truncate();
    IntegrationDatabase::disconnectAll();
});

function isolationGateState(): ProjectionGateState
{
    return DB::transaction(
        fn (): ProjectionGateState => new ProjectionGate(DB::connection())->inspectPaymentDetails(),
    );
}

it('restores the seeded projection control row that an earlier test leaked or deleted', function (string $leak, ProjectionGateState $closed): void {
    match ($leak) {
        'rebuilding' => DB::table('payment_projection_controls')->update([
            'state' => ProjectionGate::REBUILDING,
            'owner_token' => (string) Str::uuid7(),
            'owner_pid' => 1,
            'started_at' => DB::raw('now()'),
            'cleared_at' => DB::raw('now()'),
            'updated_at' => DB::raw('now()'),
        ]),
        'missing' => DB::table('payment_projection_controls')->delete(),
    };

    expect(DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projection_controls')->where('state', ProjectionGate::READY)->count())->toBe(0)
        ->and(isolationGateState())->toBe($closed);

    IntegrationDatabase::truncate();

    $rows = DB::connection(IntegrationDatabase::OBSERVER)->table('payment_projection_controls')->get();

    expect($rows)->toHaveCount(1)
        ->and((array) $rows->first())->toMatchArray([
            'projection' => ProjectionGate::PAYMENT_DETAILS,
            'state' => ProjectionGate::READY,
            'owner_token' => null,
            'owner_pid' => null,
            'started_at' => null,
            'cleared_at' => null,
        ])
        ->and(isolationGateState())->toBe(ProjectionGateState::Open);
})->with([
    'an abandoned rebuild' => ['rebuilding', ProjectionGateState::ClosedByControlState],
    'a deleted control row' => ['missing', ProjectionGateState::ClosedByMissingControlRow],
]);
