<?php

declare(strict_types=1);

return [

    // A literal, not env(): OAuth authorization must never resolve merchants on another session guard.
    'guard' => 'merchant_web',

    // The one client whose consent screen is skipped. Empty means no client is, which is the safe default.
    'bff_client_id' => env('PASSPORT_BFF_CLIENT_ID'),

    'middleware' => [],

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    'connection' => env('PASSPORT_CONNECTION'),

];
