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

    'firebase' => [
        'server_key' => env('FIREBASE_SERVER_KEY'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'gemini' => [
        'keys' => array_values(array_filter([
            env('API_KEY_GEMINI1'),
            env('API_KEY_GEMINI2'),
            env('API_KEY_GEMINI3'),
            env('API_KEY_GEMINI4'),
            env('API_KEY_GEMINI5'),
            env('API_KEY_GEMINI6'),
            env('API_KEY_GEMINI7'),
            env('API_KEY_GEMINI8'),
            env('API_KEY_GEMINI9'),
            env('API_KEY_GEMINI10'),
            env('API_KEY_GEMINI11'),
            env('API_KEY_GEMINI12'),
            env('API_KEY_GEMINI13'),
            env('API_KEY_GEMINI14'),
        ])),
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 25),
        'cooldown_seconds' => (int) env('GEMINI_KEY_COOLDOWN_SECONDS', 300),
    ],

];
