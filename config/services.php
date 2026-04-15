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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI') ?: null,
        'calendar_id' => env('GOOGLE_CALENDAR_ID', 'primary'),
        'admin_secret' => env('GOOGLE_ADMIN_SECRET'),
    ],

    'telegram' => [
        'api_token' => env('TELEGRAM_API_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        /** URL pública HTTPS del webhook; si vacío, se usa route('telegram.webhook') con APP_URL. */
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
        /** Si true, cada webhook escribe en log las claves del update (útil para ver si llega message_reaction). */
        'log_update_keys' => env('TELEGRAM_LOG_UPDATE_KEYS', false),
    ],

    /*
    | Values shown on /meta-privacy (Meta / Instagram Graph API policy page).
    */
    'meta' => [
        'privacy_controller' => env('META_PRIVACY_CONTROLLER'),
    ],

    /*
    | Instagram / Meta webhooks (comments, messaging, etc.)
    | App secret: App Dashboard → Settings → Basic → App secret
    */
    'instagram' => [
        'webhook_verify_token' => env('INSTAGRAM_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'log_payload' => env('INSTAGRAM_WEBHOOK_LOG_PAYLOAD', false),
    ],

];
