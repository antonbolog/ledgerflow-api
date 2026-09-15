<?php

declare(strict_types=1);

use Modules\Payments\Domain\Payment\Events\PaymentInitiated;

return [

    // A literal false, never env(): true would swallow an outbox writer failure and commit the event without its envelope.
    'catch_exceptions' => false,

    // Aliases are durable storage keys. The dotted key stays literal because Arr::get() checks exists() before splitting.
    'event_class_map' => [
        PaymentInitiated::ALIAS => PaymentInitiated::class,
    ],

    // Off: a test discovers every storable event and asserts the map names exactly those classes.
    'enforce_event_class_map' => false,
];
