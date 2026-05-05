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
        /** Page or User token from Meta (API con inicio de sesión Instagram / generador del panel). */
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        /** Instagram professional account id (Graph), ej. 1784140… del panel “Generar identificador”. */
        'business_account_id' => env('INSTAGRAM_BUSINESS_ACCOUNT_ID'),
        /** graph.facebook.com (tokens EAA… página/usuario). */
        'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
        /** graph.instagram.com (tokens IGAA… API con inicio de sesión Instagram). */
        'instagram_api_version' => env('INSTAGRAM_API_VERSION', 'v21.0'),
        /** Log every incoming webhook (method, IP, verification hints or POST meta). */
        'log_requests' => env('INSTAGRAM_WEBHOOK_LOG_REQUESTS', true),
        /** Also log full JSON body (verbose). */
        'log_payload' => env('INSTAGRAM_WEBHOOK_LOG_PAYLOAD', false),
    ],

    /*
    | OpenRouter (API compatible con OpenAI: /v1/chat/completions).
    */
    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'default_model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        /** Embeddings vía POST /embeddings. */
        'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'intfloat/multilingual-e5-large'),
        /** Clasificador: ¿el comentario pretendía activar una de las keywords candidatas? */
        'keyword_intent_model' => env('OPENROUTER_KEYWORD_INTENT_MODEL', 'openai/gpt-4o-mini'),
        /** Opcional: URL pública de tu app (OpenRouter la usa en rankings). */
        'http_referer' => env('OPENROUTER_HTTP_REFERER'),
        'app_title' => env('OPENROUTER_APP_TITLE', env('APP_NAME', 'Laravel')),
        'timeout' => env('OPENROUTER_TIMEOUT', 120),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];
