<?php

namespace App\Console\Commands;

use App\Models\BillingPlan;
use App\Services\Billing\BillingUsageExclusionService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Throwable;

class BillingDiagnoseCommand extends Command
{
    protected $signature = 'billing:diagnose';

    protected $description = 'Check billing production readiness without exposing secrets';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $this->info('Billing diagnostics');
        $this->line('');

        $this->diagnoseMode();
        $this->diagnosePlans();
        $this->diagnoseYooKassa();
        $this->diagnoseRoutes();
        $this->diagnoseUsageExclusions();

        $this->line('');
        $this->info("Done: {$this->failures} errors, {$this->warnings} warnings.");

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function diagnoseMode(): void
    {
        $enabled = (bool) config('billing.enabled', false);
        $mode = (string) config('billing.mode', 'off');
        $allowedModes = ['off', 'admin_only', 'visible', 'checkout', 'enforced'];

        $this->line('Mode');
        $this->check('BILLING_MODE is valid', in_array($mode, $allowedModes, true), "mode={$mode}");
        $this->check('Billing enabled state resolved', true, $enabled ? 'enabled' : 'disabled');
        $this->check('User UI capability', true, $this->yesNo(config('billing.user_ui_enabled')));
        $this->check('Checkout capability', true, $this->yesNo(config('billing.checkout_enabled')));
        $this->check('Payments capability', true, $this->yesNo(config('billing.payments.enabled')));
        $this->check('Enforcement capability', true, $this->yesNo(config('billing.enforcement_enabled')));
        $this->check('Default plan configured', (string) config('billing.default_plan', '') !== '', (string) config('billing.default_plan', ''));
    }

    private function diagnosePlans(): void
    {
        $this->line('');
        $this->line('Plans');

        try {
            $defaultPlanCode = (string) config('billing.default_plan', 'free');
            $defaultPlan = BillingPlan::query()->where('code', $defaultPlanCode)->first();
            $free = BillingPlan::query()->where('code', 'free')->first();
            $legacy = BillingPlan::query()->where('code', 'legacy_unlimited')->first();
        } catch (Throwable $e) {
            $this->check('Billing plans can be read from database', false, $e->getMessage());
            return;
        }

        $billingEnabled = (bool) config('billing.enabled', false);

        $this->check(
            "Default plan [{$defaultPlanCode}] exists",
            $defaultPlan !== null,
            $defaultPlan ? ($defaultPlan->is_active ? 'active' : 'inactive') : 'not found',
            ! $billingEnabled,
        );

        $this->check(
            'Default plan is free',
            $defaultPlanCode === 'free',
            "current={$defaultPlanCode}",
            ! $billingEnabled,
        );

        $this->check('Free plan exists', $free !== null, $free ? 'found' : 'not found', ! $billingEnabled);

        if ($free) {
            $freeMetadata = $free->metadata_json ?? [];
            $this->check('Free plan is active', (bool) $free->is_active, $free->is_active ? 'active' : 'inactive', ! $billingEnabled);
            $this->check('Free plan is public', ! $this->metadataBool($freeMetadata, 'hidden', true), $this->hiddenLabel($freeMetadata), ! $billingEnabled);
            $this->check('Free plan is not system', ! $this->metadataBool($freeMetadata, 'system', false), $this->systemLabel($freeMetadata), ! $billingEnabled);
            $this->check('Free plan is free', $this->priceMinor($freeMetadata) === 0, 'price_minor=' . $this->priceMinor($freeMetadata), ! $billingEnabled);
        }

        $this->check('legacy_unlimited exists', $legacy !== null, $legacy ? 'found' : 'not found', true);

        if ($legacy) {
            $legacyMetadata = $legacy->metadata_json ?? [];
            $legacyHiddenOrSystem = $this->metadataBool($legacyMetadata, 'hidden', true)
                || $this->metadataBool($legacyMetadata, 'system', false)
                || $this->metadataBool($legacyMetadata, 'internal', false);

            $this->check(
                'legacy_unlimited is not public purchasable',
                $legacyHiddenOrSystem,
                $legacyHiddenOrSystem ? 'hidden/system/internal' : 'public',
            );
        }

        $publicPaidPlans = BillingPlan::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (BillingPlan $plan) => $this->isPublicPaidPlan($plan));

        $this->check(
            'Public paid plans exist',
            $publicPaidPlans->isNotEmpty(),
            'count=' . $publicPaidPlans->count(),
            ! in_array((string) config('billing.mode'), ['checkout', 'enforced'], true),
        );

        $unsafePublicPlans = BillingPlan::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (BillingPlan $plan) => $this->isUnsafePublicPlan($plan));

        $this->check(
            'Hidden/system/sandbox plans are excluded from public paid set',
            $unsafePublicPlans->isEmpty(),
            $unsafePublicPlans->pluck('code')->implode(', ') ?: 'ok',
        );
    }

    private function diagnoseYooKassa(): void
    {
        $this->line('');
        $this->line('YooKassa');

        $provider = (string) config('billing.provider', 'yookassa');
        $providerMode = (string) config('billing.provider_mode', 'test');
        $shopId = (string) config('billing.payments.providers.yookassa.shop_id', '');
        $secret = (string) config('billing.payments.providers.yookassa.secret_key', '');
        $returnUrl = (string) config('billing.payments.providers.yookassa.return_url', '');
        $apiBase = (string) config('billing.payments.providers.yookassa.api_base', '');
        $receiptsEnabled = config('billing.payments.providers.yookassa.receipts_enabled');
        $checkoutMode = in_array((string) config('billing.mode'), ['checkout', 'enforced'], true);

        $this->check('Provider is YooKassa', $provider === 'yookassa', "provider={$provider}");
        $this->check('Provider mode is valid', in_array($providerMode, ['test', 'live'], true), "mode={$providerMode}");
        $this->check('Shop ID is configured', $shopId !== '', $this->mask($shopId), ! $checkoutMode);
        $this->check('Secret key is configured', $secret !== '', $this->mask($secret), ! $checkoutMode);
        $this->check('Return URL is configured', $returnUrl !== '', $returnUrl ?: 'empty', ! $checkoutMode);
        $this->check('Return URL points to user payment result', str_contains($returnUrl, '/billing/payment-result'), $returnUrl ?: 'empty');
        $this->check('Return URL does not point to admin area', ! str_contains($returnUrl, '/admin/'), $returnUrl ?: 'empty');
        $this->check('API base is HTTPS', str_starts_with($apiBase, 'https://'), $apiBase ?: 'empty');
        $this->check('Receipt flag is boolean', is_bool($receiptsEnabled), 'type=' . gettype($receiptsEnabled));
        $this->check('Receipt VAT code is configured', (int) config('billing.payments.providers.yookassa.receipt_vat_code') > 0, (string) config('billing.payments.providers.yookassa.receipt_vat_code'));
        $this->check('Receipt payment subject is configured', (string) config('billing.payments.providers.yookassa.receipt_payment_subject') !== '', (string) config('billing.payments.providers.yookassa.receipt_payment_subject'));
        $this->check('Receipt payment mode is configured', (string) config('billing.payments.providers.yookassa.receipt_payment_mode') !== '', (string) config('billing.payments.providers.yookassa.receipt_payment_mode'));
    }

    private function diagnoseRoutes(): void
    {
        $this->line('');
        $this->line('Routes');

        $this->check('Checkout endpoint exists', $this->routeExists('POST', 'api/billing/checkout'), 'POST /api/billing/checkout');
        $this->check('Payment result endpoint exists', $this->routeExists('GET', 'api/billing/payment-result'), 'GET /api/billing/payment-result');
        $this->check('YooKassa webhook endpoint exists', $this->routeExists('POST', 'api/billing/webhooks/{provider}'), 'POST /api/billing/webhooks/{provider}');
        $this->check('YooKassa webhook is not behind auth:sanctum', $this->webhookIsPublic(), 'POST /api/billing/webhooks/{provider}');

        if (in_array((string) config('billing.mode'), ['checkout', 'enforced'], true)) {
            $this->check('Checkout capability is enabled in current mode', (bool) config('billing.checkout_enabled') && (bool) config('billing.payments.enabled'), 'mode=' . config('billing.mode'));
        } else {
            $this->check('Checkout capability is disabled outside checkout/enforced', ! (bool) config('billing.checkout_enabled'), 'mode=' . config('billing.mode'), true);
        }
    }

    private function diagnoseUsageExclusions(): void
    {
        $this->line('');
        $this->line('Usage exclusions');

        $ignored = app(BillingUsageExclusionService::class)->shouldIgnoreUserId(1);
        $this->check('user_id=1 is ignored for billing usage/gate events', $ignored, $ignored ? 'ignored' : 'not ignored');
    }

    private function check(string $label, bool $passed, string $details = '', bool $warning = false): void
    {
        if ($passed) {
            $this->line("<info>[OK]</info> {$label}" . ($details !== '' ? " ({$details})" : ''));
            return;
        }

        if ($warning) {
            $this->warnings++;
            $this->line("<comment>[WARN]</comment> {$label}" . ($details !== '' ? " ({$details})" : ''));
            return;
        }

        $this->failures++;
        $this->line("<error>[FAIL]</error> {$label}" . ($details !== '' ? " ({$details})" : ''));
    }

    private function routeExists(string $method, string $uri): bool
    {
        return collect(Route::getRoutes())->contains(function ($route) use ($method, $uri) {
            return in_array($method, $route->methods(), true)
                && trim($route->uri(), '/') === trim($uri, '/');
        });
    }

    private function webhookIsPublic(): bool
    {
        $route = collect(Route::getRoutes())->first(function ($route) {
            return in_array('POST', $route->methods(), true)
                && trim($route->uri(), '/') === 'api/billing/webhooks/{provider}';
        });

        if (! $route) {
            return false;
        }

        return ! collect($route->gatherMiddleware())->contains(fn (string $middleware) => str_contains($middleware, 'auth:sanctum'));
    }

    private function isPublicPaidPlan(BillingPlan $plan): bool
    {
        $metadata = $plan->metadata_json ?? [];

        return $plan->code !== 'legacy_unlimited'
            && ! $this->metadataBool($metadata, 'hidden', true)
            && ! $this->metadataBool($metadata, 'system', false)
            && ! $this->metadataBool($metadata, 'sandbox', false)
            && ! $this->metadataBool($metadata, 'internal', false)
            && $this->priceMinor($metadata) > 0
            && strtoupper((string) Arr::get($metadata, 'currency', 'RUB')) === 'RUB';
    }

    private function isUnsafePublicPlan(BillingPlan $plan): bool
    {
        $metadata = $plan->metadata_json ?? [];

        if ($this->metadataBool($metadata, 'hidden', true)) {
            return false;
        }

        return $plan->code === 'legacy_unlimited'
            || $this->metadataBool($metadata, 'system', false)
            || $this->metadataBool($metadata, 'sandbox', false)
            || $this->metadataBool($metadata, 'internal', false);
    }

    private function metadataBool(array $metadata, string $key, bool $default): bool
    {
        return (bool) Arr::get($metadata, $key, $default);
    }

    private function priceMinor(array $metadata): int
    {
        return is_numeric($metadata['price_minor'] ?? null) ? (int) $metadata['price_minor'] : 0;
    }

    private function hiddenLabel(array $metadata): string
    {
        return $this->metadataBool($metadata, 'hidden', true) ? 'hidden' : 'public';
    }

    private function systemLabel(array $metadata): string
    {
        return $this->metadataBool($metadata, 'system', false) ? 'system' : 'regular';
    }

    private function yesNo(mixed $value): string
    {
        return (bool) $value ? 'yes' : 'no';
    }

    private function mask(string $value): string
    {
        if ($value === '') {
            return 'empty';
        }

        if (mb_strlen($value) <= 8) {
            return str_repeat('*', mb_strlen($value));
        }

        return mb_substr($value, 0, 4) . '...' . mb_substr($value, -4);
    }
}
