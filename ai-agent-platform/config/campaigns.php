<?php

return [
    // When false, slots are only drafted this long before publish. When true (default),
    // drafting starts ASAP after launch; scheduled_at is only for SocialAPI publish time.
    'draft_asap' => filter_var(env('CAMPAIGNS_DRAFT_ASAP', true), FILTER_VALIDATE_BOOL),

    // Used only when draft_asap is false.
    'lookahead_minutes' => (int) env('CAMPAIGNS_LOOKAHEAD_MINUTES', 180),

    // A dispatched slot that is still pending after this long is queued again.
    'redispatch_after_minutes' => (int) env('CAMPAIGNS_REDISPATCH_AFTER_MINUTES', 30),

    'queue' => env('CAMPAIGNS_QUEUE', 'campaigns'),

    'log_channel' => env('CAMPAIGNS_LOG_CHANNEL', 'campaigns'),
];
