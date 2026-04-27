<?php

return [
    'enabled' => env('BILLING_ENABLED', false),
    'track_usage' => env('BILLING_TRACK_USAGE', true),
    'enforce_limits' => env('BILLING_ENFORCE_LIMITS', false),
    'log_only' => env('BILLING_LOG_ONLY', true),
    'fail_open' => env('BILLING_FAIL_OPEN', true),
    'default_plan' => env('BILLING_DEFAULT_PLAN', 'legacy_unlimited'),
    'admin_ui_enabled' => env('BILLING_ADMIN_UI_ENABLED', false),
    'storage_tracking_enabled' => env('BILLING_STORAGE_TRACKING_ENABLED', false),
    'payments' => [
        'enabled' => env('BILLING_PAYMENTS_ENABLED', false),
        'checkout_ui_enabled' => env('BILLING_CHECKOUT_UI_ENABLED', false),
        'default_provider' => env('BILLING_PROVIDER_DEFAULT', 'yookassa'),
        'providers' => [
            'yookassa' => [
                'enabled' => env('BILLING_PROVIDER_YOOKASSA_ENABLED', false),
                'mode' => env('BILLING_PROVIDER_YOOKASSA_MODE', 'test'),
                'shop_id' => env('YOOKASSA_SHOP_ID'),
                'secret_key' => env('YOOKASSA_SECRET_KEY'),
                'return_url' => env('YOOKASSA_RETURN_URL'),
                'api_base' => env('YOOKASSA_API_BASE', 'https://api.yookassa.ru/v3'),
                'receipts_enabled' => env('BILLING_YOOKASSA_RECEIPTS_ENABLED', false),
                'receipt_vat_code' => (int) env('BILLING_YOOKASSA_RECEIPT_VAT_CODE', 1),
                'receipt_payment_subject' => env('BILLING_YOOKASSA_RECEIPT_PAYMENT_SUBJECT', 'service'),
                'receipt_payment_mode' => env('BILLING_YOOKASSA_RECEIPT_PAYMENT_MODE', 'full_prepayment'),
            ],
        ],
    ],
];
