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

    // NVIDIA NIM — optional safe-AI backend (ADR 0012 / ADR 0013).
    // Out-of-band only. Never invoked from Livewire/Filament/queue
    // jobs that gate analyst-visible state. Disabled (gracefully)
    // when api_key is empty.
    //
    // Dependency authorisation:
    //   calibration_proposals.surface='ai_runtime',
    //                        field='nvidia_nim_dependency'
    //   (seeded 2026-04-30, status=adopted, ADR 0012 self-signoff)
    'nvidia_nim' => [
        'api_key' => env('NVIDIA_NIM_API_KEY'),
        'base_url' => env('NVIDIA_NIM_BASE_URL', 'https://integrate.api.nvidia.com/v1'),

        // Tiered model routing.
        // - primary  = fast loop model (hypothesis refinement, anomaly
        //              explanation, signal fusion reasoning). Reasoning
        //              model with short answers — low latency budget.
        // - fallback = structured/JSON model used when primary fails
        //              (404, transport, etc.). Acts as JSON-mode safety
        //              net for the same prompt.
        // - heavy    = final summary / "what changed" / CI command
        //              surface generation. Higher quality, larger
        //              token budget; called from output-phase code.
        // - safety   = optional content-safety pass. Stubbed; not
        //              wired into the synthesis pipeline yet.
        'primary_model' => env('NVIDIA_NIM_PRIMARY_MODEL', 'stepfun-ai/step-3.5-flash'),
        'fallback_model' => env('NVIDIA_NIM_FALLBACK_MODEL', 'z-ai/glm4.7'),
        'heavy_model' => env('NVIDIA_NIM_HEAVY_MODEL', 'mistralai/mistral-large-3-675b-instruct-2512'),
        'safety_model' => env('NVIDIA_NIM_SAFETY_MODEL', 'nvidia/nemotron-content-safety-reasoning'),

        'timeout_seconds' => (int) env('NVIDIA_NIM_TIMEOUT', 30),
        'heavy_timeout_seconds' => (int) env('NVIDIA_NIM_HEAVY_TIMEOUT', 180),
        'connect_timeout_seconds' => (int) env('NVIDIA_NIM_CONNECT_TIMEOUT', 5),
        'max_retries' => (int) env('NVIDIA_NIM_MAX_RETRIES', 2),
        'retry_base_ms' => (int) env('NVIDIA_NIM_RETRY_BASE_MS', 500),
        'temperature' => (float) env('NVIDIA_NIM_TEMPERATURE', 0.2),
        'heavy_temperature' => (float) env('NVIDIA_NIM_HEAVY_TEMPERATURE', 0.15),
        'max_tokens' => (int) env('NVIDIA_NIM_MAX_TOKENS', 1500),
        'heavy_max_tokens' => (int) env('NVIDIA_NIM_HEAVY_MAX_TOKENS', 4096),
        'json_mode' => filter_var(env('NVIDIA_NIM_JSON_MODE', true), FILTER_VALIDATE_BOOL),
    ],

];
