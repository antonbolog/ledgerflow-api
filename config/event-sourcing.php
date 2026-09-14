<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;

/*
|--------------------------------------------------------------------------
| Event sourcing
|--------------------------------------------------------------------------
|
| MINIMAL BY DESIGN. Hand-written, not a vendor:publish dump.
| spatie/laravel-package-tools merges the package defaults during register(),
| and mergeConfigFrom() is a top-level array_merge in which the application file
| wins per key — so every key absent here keeps its vendor default and stays
| reviewable by reading the vendor file. A full publish would import roughly
| fifteen unreviewed keys, the criticism this project already records against
| config/sanctum.php.
|
*/

return [

    /*
     * FALSE, LITERALLY, AND NOT FROM env().
     *
     * Projectionist copies this into a private property when the singleton is
     * constructed, and the package resolves that singleton during
     * packageBooted(), so a later change has no effect. If this were ever true,
     * a throwing outbox writer would be swallowed and the stored event would
     * commit WITHOUT its outbox row — destroying the atomicity T3 measured.
     *
     * Scoping note: T5 ships no synchronous handler, so this protects T6's
     * invariant rather than one of T5's. It is here because T5 must create this
     * file anyway and leaving the environment hole open until T6 is worse.
     */
    'catch_exceptions' => false,

    /*
     * The durable alias for every storable event. stored_events.event_class and
     * payment_outbox.event_type carry the alias, never the class name, so the
     * namespace can move without rewriting history.
     *
     * The dotted key is safe: Arr::get() checks Arr::exists() before it splits
     * on '.', so this resolves as a literal key and never as nested
     * ['payments']['payment_initiated'].
     */
    'event_class_map' => [
        PaymentInitiated::ALIAS => PaymentInitiated::class,
    ],

    /*
     * FALSE ON PURPOSE. Enforcement throws for any unmapped class. Completeness is
     * asserted instead by a test that discovers every production storable event
     * and requires the map above to name exactly those classes.
     */
    'enforce_event_class_map' => false,
];
