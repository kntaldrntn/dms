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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'intake_ai' => [
        // gpt-6-luna scored best and cost least per scan in the 2026-10-23 comparison (documentation.md).
        'model' => env('DMS_INTAKE_AI_MODEL') ?: 'gpt-6-luna',
        // Reasoning models only (gpt-5 and later). "none" was as accurate as "medium" at a fifth of the output tokens.
        'reasoning_effort' => env('DMS_INTAKE_AI_REASONING_EFFORT') ?: 'none',
        // OCR text sent to the model is capped to keep each intake call cheap.
        'max_text_characters' => 9000,
    ],

];
