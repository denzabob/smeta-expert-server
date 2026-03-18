<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Verification Code Settings
    |--------------------------------------------------------------------------
    */

    'code_ttl_minutes' => (int) env('VERIFICATION_CODE_TTL', 5),
    'max_attempts' => (int) env('VERIFICATION_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('VERIFICATION_RESEND_COOLDOWN', 60),

    /*
    |--------------------------------------------------------------------------
    | Test / Dev Mode
    |--------------------------------------------------------------------------
    | When test_mode is true, codes are logged instead of sent.
    | test_code sets a fixed code for dev/QA convenience.
    */

    'test_mode' => (bool) env('VERIFICATION_TEST_MODE', false),
    'test_code' => env('VERIFICATION_TEST_CODE', null), // e.g. '123456'

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'per_phone_ip_hour' => (int) env('VERIFICATION_RATE_PHONE_IP', 5),
        'per_ip_hour' => (int) env('VERIFICATION_RATE_IP', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram Gateway Transport
    |--------------------------------------------------------------------------
    */

    'telegram_gateway' => [
        'enabled' => (bool) env('TELEGRAM_GATEWAY_ENABLED', false),
        'token' => env('TELEGRAM_GATEWAY_TOKEN'),
        'ttl' => (int) env('TELEGRAM_GATEWAY_TTL', 10), // HTTP timeout seconds
        'code_ttl' => (int) env('TELEGRAM_GATEWAY_CODE_TTL', 300), // Code expiry for TG
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS.ru CallCheck Transport
    |--------------------------------------------------------------------------
    */

    'sms_ru' => [
        'enabled' => (bool) env('SMSRU_ENABLED', false),
        'api_id' => env('SMSRU_API_ID'),
        'timeout' => (int) env('SMSRU_CALLCHECK_TIMEOUT', 15),
        'test_confirmed' => (bool) env('SMSRU_CALLCHECK_TEST_CONFIRMED', true),
        'test_call_phone' => env('SMSRU_CALLCHECK_TEST_PHONE', '74995555555'),
        'test_call_phone_pretty' => env('SMSRU_CALLCHECK_TEST_PHONE_PRETTY', '+7 (499) 555-5555'),
        'official_library' => [
            'enabled' => (bool) env('SMSRU_OFFICIAL_LIBRARY_ENABLED', false),
            'path' => env('SMSRU_OFFICIAL_LIBRARY_PATH', base_path('integrations/smsru/official/sms.ru.php')),
            'class' => env('SMSRU_OFFICIAL_LIBRARY_CLASS', 'SMSRU'),
        ],
        'webhook' => [
            'enabled' => (bool) env('SMSRU_CALLCHECK_WEBHOOK_ENABLED', true),
            'token' => env('SMSRU_CALLCHECK_WEBHOOK_TOKEN'),
        ],
    ],

];
