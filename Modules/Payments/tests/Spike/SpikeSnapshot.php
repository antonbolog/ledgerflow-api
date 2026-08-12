<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Spatie\EventSourcing\Snapshots\EloquentSnapshot;

/**
 * Pinned for the same reason as SpikeStoredEvent, and needed even though the spike
 * never writes a snapshot.
 *
 * AggregateRoot::retrieve() calls reconstituteFromEvents(), which asks the snapshot
 * repository for a snapshot before it reads any event. Left on the default
 * connection that read would run inside RefreshDatabase's wrapper transaction,
 * which would make the default connection something other than a pure observer.
 * Pinning it keeps every statement Spatie issues on pgsql_spike.
 */
final class SpikeSnapshot extends EloquentSnapshot
{
    protected $connection = SpikeDatabase::CONNECTION;
}
