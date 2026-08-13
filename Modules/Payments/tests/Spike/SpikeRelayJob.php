<?php

declare(strict_types=1);

namespace Modules\Payments\Tests\Spike;

/**
 * The job a relay pass publishes: an event id and nothing else.
 *
 * The outbox spec puts the canonical envelope in PostgreSQL and has the
 * consumer load it from there, so the message carries an identifier rather than
 * business data. The Q9 test asserts that by inspecting the decoded payload.
 *
 * THIS CLASS MUST NOT IMPLEMENT ShouldQueueAfterCommit, must not declare an
 * $afterCommit property, and must never be dispatched with ->afterCommit().
 * Queue::shouldDispatchAfterCommit() checks the interface and that property
 * FIRST and only then falls back to the connection's after_commit config, so
 * any of the three would silently override a connection configured for
 * immediate publication. That is a trap T7 could walk into by adding an
 * interface for unrelated reasons.
 *
 * No worker ever runs it, so only its serialization matters. handle() exists so
 * the class is a legitimate queueable shape rather than an empty marker.
 */
final readonly class SpikeRelayJob
{
    public function __construct(public string $eventId) {}

    public function handle(): void
    {
        // Deliberately empty. T4 measures publication, not consumption.
    }
}
