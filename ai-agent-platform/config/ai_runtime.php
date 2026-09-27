<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Runtime driver
    |--------------------------------------------------------------------------
    |
    | php = legacy in-process AgentLoop (default)
    | sk  = Microsoft Agent Framework / Semantic Kernel Python sidecar
    |
    */
    'driver' => env('AI_RUNTIME', 'php'),

    'url' => rtrim((string) env('AI_RUNTIME_URL', 'http://127.0.0.1:8090'), '/'),

    'service_key' => env('AI_RUNTIME_SERVICE_KEY', env('AI_RUNTIME_INTERNAL_KEY', '')),

    'internal_key' => env('AI_RUNTIME_INTERNAL_KEY', env('AI_RUNTIME_SERVICE_KEY', '')),

    'timeout' => (int) env('AI_RUNTIME_TIMEOUT', 200),

    /*
    | Per-business override: business ids forced onto sk even when driver=php (or vice versa).
    | Example: AI_RUNTIME_SK_BUSINESSES=1,2,3
    */
    'sk_business_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('AI_RUNTIME_SK_BUSINESSES', '')),
    ))),

    'php_business_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('AI_RUNTIME_PHP_BUSINESSES', '')),
    ))),

    /*
    | Quiet window (seconds) before running the customer agent on an inbound.
    | Later messages in the same conversation reset the window and supersede older jobs,
    | so "ok" + "thank you" get one reply. Set 0 to disable (immediate, legacy behavior).
    */
    'inbound_debounce_seconds' => max(0, (int) env('AI_INBOUND_DEBOUNCE_SECONDS', 3)),
];
