<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fal' => [
        'key' => env('FAL_KEY'),
        'model' => env('FAL_LLM_MODEL', 'google/gemini-2.5-flash'),
        // Catalog key (gpt_image_2|nano_banana_2|flux), not a raw Fal endpoint.
        'image_model' => env('FAL_IMAGE_MODEL', 'gpt_image_2'),
    ],

    'socialapi' => [
        'key' => env('SOCAPI_KEY', env('SAPI_KEY')),
        'webhook_secret' => env('SOCAPI_WEBHOOK_SECRET'),
        'base_url' => env('SOCAPI_BASE_URL', 'https://api.social-api.ai/v1'),
        'redirect_uri' => env('SOCAPI_REDIRECT_URI', env('APP_URL').'/socialapi/callback'),
        'mcp_url' => env('SOCAPI_MCP_URL', 'https://api.social-api.ai/mcp'),
        'mcp_enabled' => env('SOCAPI_MCP_ENABLED', env('APP_ENV') !== 'testing'),
    ],

    'wasl_mcp' => [
        'rate_per_minute' => (int) env('WASL_MCP_RATE_PER_MINUTE', 120),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => ltrim((string) env('TELEGRAM_BOT_USERNAME', ''), '@'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET', ''),
    ],

];
