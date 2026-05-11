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

    /*
    |--------------------------------------------------------------------------
    | OpenRouter AI Service
    |--------------------------------------------------------------------------
    */
    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'temperature' => env('OPENROUTER_TEMPERATURE', 0.2),
        'max_tokens' => env('OPENROUTER_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | DeepSeek AI Service
    |--------------------------------------------------------------------------
    */
    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'temperature' => env('DEEPSEEK_TEMPERATURE', 0.2),
        'max_tokens' => env('DEEPSEEK_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mistral AI Service
    |--------------------------------------------------------------------------
    */
    'mistral' => [
        'key' => env('MISTRAL_API_KEY'),
        'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
        'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        'temperature' => env('MISTRAL_TEMPERATURE', 0.2),
        'max_tokens' => env('MISTRAL_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | RouterAI Service
    |--------------------------------------------------------------------------
    */
    'routerai' => [
        'key' => env('ROUTERAI_API_KEY'),
        'model' => env('ROUTERAI_MODEL', 'openai/gpt-4o'),
        'base_url' => env('ROUTERAI_BASE_URL', 'https://routerai.ru/api/v1'),
        'temperature' => env('ROUTERAI_TEMPERATURE', 0.2),
        'max_tokens' => env('ROUTERAI_MAX_TOKENS', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Yandex ID OAuth
    |--------------------------------------------------------------------------
    */
    'yandex' => [
        'client_id' => env('YANDEX_ID_CLIENT_ID'),
        'client_secret' => env('YANDEX_ID_CLIENT_SECRET'),
        'redirect_uri' => env('YANDEX_ID_REDIRECT_URI'),
        'force_confirm' => env('YANDEX_ID_FORCE_CONFIRM', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | VK ID OAuth
    |--------------------------------------------------------------------------
    */
    'vk' => [
        'client_id' => env('VK_ID_CLIENT_ID'),
        'client_secret' => env('VK_ID_CLIENT_SECRET'),
        'redirect_uri' => env('VK_ID_REDIRECT_URI'),
        'scope' => env('VK_ID_SCOPE', 'vkid.personal_info email phone'),
    ],

];
