<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * Spatie's stored-event model, pinned to the spike connection.
 *
 * The parent pins $table = 'stored_events' but declares no $connection, so it
 * follows Eloquent's default connection. Under RefreshDatabase that is the wrapped
 * connection, whose transaction never commits, so the spike could never observe a
 * real COMMIT boundary. Subclassing is the package's supported extension point:
 * EloquentStoredEventRepository reads config('event-sourcing.stored_event_model')
 * at construction and rejects anything not extending EloquentStoredEvent.
 *
 * $table is deliberately NOT redeclared. Pinning the connection must not also move
 * the table, and the guard in the test asserts both.
 */
final class SpikeStoredEvent extends EloquentStoredEvent
{
    protected $connection = SpikeDatabase::CONNECTION;
}
