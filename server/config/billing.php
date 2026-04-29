<?php

$billingEnabled = (bool) env('BILLING_ENABLED', false);
$mode = strtolower((string) env('BILLING_MODE', 'off'));
$allowedModes = ['off', 'admin_only', 'visible', 'checkout', 'enforced'];

if (! in_array($mode, $allowedModes, true)) {
    $mode = 'off';
}

$enabled = $billingEnabled && $mode !== 'off';
$adminUiEnabled = $enabled;
$userUiEnabled = $enabled && in_array($mode, ['visible', 'checkout', 'enforced'], true);
$checkoutEnabled = $enabled && in_array($mode, ['checkout', 'enforced'], true);
$paymentsEnabled = $checkoutEnabled;
$enforcementEnabled = $enabled && $mode === 'enforced';
$usageTrackingEnabled = $enabled && $mode !== 'off';
$logOnly = $mode !== 'enforced';
$provider = (string) env('BILLING_PROVIDER_DEFAULT', 'yookassa');
$providerMode = $provider === 'yookassa'
    ? (string) env('BILLING_PROVIDER_YOOKASSA_MODE', 'test')
    : 'test';

return [
    'enabled' => $enabled,
    'mode' => $mode,
    'admin_ui_enabled' => $adminUiEnabled,
    'user_ui_enabled' => $userUiEnabled,
    'checkout_enabled' => $checkoutEnabled,
    'payments_enabled' => $paymentsEnabled,
    'enforcement_enabled' => $enforcementEnabled,
    'usage_tracking_enabled' => $usageTrackingEnabled,
    'provider' => $provider,
    'provider_mode' => $providerMode,
    'fail_open' => env('BILLING_FAIL_OPEN', true),
    'default_plan' => env('BILLING_DEFAULT_PLAN', 'legacy_unlimited'),

    // Backward-compatible aliases. They are intentionally derived only from
    // BILLING_ENABLED + BILLING_MODE and must not read legacy env flags.
    'track_usage' => $usageTrackingEnabled,
    'enforce_limits' => $enforcementEnabled,
    'log_only' => $logOnly,
    'storage_tracking_enabled' => false,

    'payments' => [
        'enabled' => $paymentsEnabled,
        'checkout_ui_enabled' => $checkoutEnabled,
        'default_provider' => $provider,
        'providers' => [
            'yookassa' => [
                'enabled' => $paymentsEnabled && $provider === 'yookassa',
                'mode' => $providerMode,
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
