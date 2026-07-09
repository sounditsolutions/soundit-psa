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

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID'),
    ],

    'ninja' => [
        'base_url' => env('NINJA_BASE_URL', 'https://app.ninjarmm.com'),
        'client_id' => env('NINJA_CLIENT_ID'),
        'client_secret' => env('NINJA_CLIENT_SECRET'),
        'scope' => env('NINJA_SCOPE', 'monitoring'),
        'request_timeout' => (int) env('NINJA_REQUEST_TIMEOUT', 30),
        'token_timeout' => (int) env('NINJA_TOKEN_TIMEOUT', 10),
    ],

    'level' => [
        'api_key' => env('LEVEL_API_KEY'),
        'base_url' => env('LEVEL_BASE_URL', 'https://api.level.io'),
        'request_timeout' => (int) env('LEVEL_REQUEST_TIMEOUT', 30),
        'webhook_secret' => env('LEVEL_WEBHOOK_SECRET'),
    ],

    'plivo' => [
        'auth_id' => env('PLIVO_AUTH_ID'),
        'auth_token' => env('PLIVO_AUTH_TOKEN'),
        'webhook_secret' => env('PLIVO_WEBHOOK_SECRET'),
        'did_number' => env('PLIVO_DID_NUMBER'),
        'app_id' => env('PLIVO_APP_ID'),
        'hold_music_url' => env('PLIVO_HOLD_MUSIC_URL'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'anthropic'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
    ],

    'graph' => [
        'tenant_id' => env('MICROSOFT_TENANT_ID'),
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'request_timeout' => (int) env('GRAPH_REQUEST_TIMEOUT', 15),
        'token_timeout' => (int) env('GRAPH_TOKEN_TIMEOUT', 10),
    ],

];
