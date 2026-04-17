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

    // Intel Copilot — Python broker reachable on the internal Docker
    // network (see infra/docker-compose.yml `intel_copilot`). All NL
    // questions from the portal chat page route through this.
    //
    // Token is shared with the Python side via INTEL_COPILOT_API_TOKEN
    // and sent as X-Intel-Copilot-Token on every request; the broker
    // rejects missing/mismatched tokens with 401.
    'intel_copilot' => [
        'url' => env('INTEL_COPILOT_URL', 'http://intel_copilot:8000'),
        'token' => env('INTEL_COPILOT_API_TOKEN'),
        'timeout' => (int) env('INTEL_COPILOT_TIMEOUT', 20),
    ],

];
