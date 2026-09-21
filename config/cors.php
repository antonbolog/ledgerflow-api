<?php

declare(strict_types=1);

// No browser origin calls this API directly: the merchant BFF calls it server to server (ADR 0006, D-7).
return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
