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

    // One pinned model for every AI feature (audit CLIN-012). A floating alias
    // such as gemini-flash-latest can change behaviour or slow down without a
    // release; switch models here or with GEMINI_MODEL.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    ],

    // Phone login has no SMS provider. Demo mode accepts the fixed code 123456
    // for every phone; with it off, send-otp answers 503.
    'otp' => [
        'demo_mode' => (bool) env('OTP_DEMO_MODE', false),
    ],

    // Self-hosted LiveKit for in-app video consultations (Phase 6).
    // `url` is what phones connect to (LAN IP); `http_url` is what Laravel
    // uses for the server API (RoomService), normally the same host locally.
    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'http_url' => env('LIVEKIT_HTTP_URL', 'http://127.0.0.1:7880'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
    ],

    // Shared secret the Python transcriber agent sends as X-Transcriber-Secret.
    'transcriber' => [
        'secret' => env('TRANSCRIBER_SECRET'),
        // How late the agent may learn of a consent change (see
        // ConsultationTranscript::consentHeldThroughout). 0 = exact.
        'consent_grace_ms' => (int) env('TRANSCRIBER_CONSENT_GRACE_MS', 2000),
        // After the doctor ends a call, wait this long for the agent's uploads
        // and /complete before finalising anyway (agent not running or crashed).
        // Keep it above the agent's 90 s shutdown timeout.
        'finalize_delay_seconds' => (int) env('TRANSCRIBER_FINALIZE_DELAY_SECONDS', 180),
    ],

];
