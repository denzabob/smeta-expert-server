<?php

namespace App\Console\Commands;

use App\Exceptions\BillingCheckoutException;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\UserCheckoutService;
use App\Services\Billing\UserPaymentRefreshService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class BillingSmokeTestCommand extends Command
{
    protected $signature = 'billing:smoke-test {--user= : User ID} {--plan= : Billing plan code}';

    protected $description = 'Run a YooKassa test-mode checkout smoke test for a user and plan';

    public function handle(UserCheckoutService $checkoutService, UserPaymentRefreshService $refreshService): int
    {
        $userId = $this->option('user');
        $planCode = $this->option('plan');

        if (! $userId || ! $planCode) {
            $this->error('Use: php artisan billing:smoke-test --user=ID --plan=PLAN_CODE');
            return self::FAILURE;
        }

        if ((string) config('billing.provider_mode') !== 'test') {
            $this->error('Smoke-test is allowed only when BILLING_PROVIDER_YOOKASSA_MODE=test.');
            return self::FAILURE;
        }

        if (! (bool) config('billing.checkout_enabled') || ! (bool) config('billing.payments.enabled')) {
            $this->error('Checkout/payments are disabled. Use BILLING_ENABLED=true and BILLING_MODE=checkout for this smoke-test.');
            return self::FAILURE;
        }

        $user = User::query()->find($userId);

        if (! $user) {
            $this->error("User [{$userId}] was not found.");
            return self::FAILURE;
        }

        $this->warn('This command creates or reuses a YooKassa TEST invoice/payment. It does not perform a real charge.');
        $this->line("User: {$user->id} {$user->email}");
        $this->line("Plan: {$planCode}");

        $invoiceCountBefore = BillingInvoice::query()->where('user_id', $user->id)->count();
        $paymentCountBefore = BillingPayment::query()->where('user_id', $user->id)->count();
        $subscriptionCountBefore = BillingSubscription::query()->where('user_id', $user->id)->count();

        try {
            $checkout = $checkoutService->createCheckout($user, (string) $planCode);
        } catch (BillingCheckoutException $e) {
            $this->error($e->getMessage());
            $this->line('Code: ' . $e->errorCode());
            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error('Validation failed.');
            foreach ($e->errors() as $field => $messages) {
                $this->line($field . ': ' . implode('; ', $messages));
            }
            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Checkout failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $payment = BillingPayment::query()
            ->with('invoice')
            ->whereKey($checkout['payment_id'] ?? null)
            ->first();

        if (! $payment || ! $payment->invoice) {
            $this->error('Checkout response did not produce a local invoice/payment.');
            return self::FAILURE;
        }

        $this->info('Checkout created.');
        $this->line('Invoice ID: ' . $payment->invoice_id);
        $this->line('Payment ID: ' . $payment->id);
        $this->line('Payment status: ' . $payment->status);
        $this->line('Confirmation URL: ' . ($checkout['confirmation_url'] ?? 'missing'));

        if (empty($checkout['confirmation_url'])) {
            $this->error('YooKassa confirmation_url is missing.');
            return self::FAILURE;
        }

        $returnUrl = (string) config('billing.payments.providers.yookassa.return_url', '');
        if (str_contains($returnUrl, '/admin/') || ! str_contains($returnUrl, '/billing/payment-result')) {
            $this->error("Configured return_url is unsafe: {$returnUrl}");
            return self::FAILURE;
        }

        try {
            $this->line('Refreshing payment status twice to verify idempotent read path...');
            $firstRefresh = $refreshService->refresh($user, $payment);
            $payment->refresh();
            $secondRefresh = $refreshService->refresh($user, $payment);
        } catch (Throwable $e) {
            $this->error('Payment refresh failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $invoiceCountAfter = BillingInvoice::query()->where('user_id', $user->id)->count();
        $paymentCountAfter = BillingPayment::query()->where('user_id', $user->id)->count();
        $subscriptionCountAfter = BillingSubscription::query()->where('user_id', $user->id)->count();

        $this->info('Refresh completed.');
        $this->line('First refresh status: ' . data_get($firstRefresh, 'payment.status', 'unknown'));
        $this->line('Second refresh status: ' . data_get($secondRefresh, 'payment.status', 'unknown'));
        $this->line('Invoices delta: ' . ($invoiceCountAfter - $invoiceCountBefore));
        $this->line('Payments delta: ' . ($paymentCountAfter - $paymentCountBefore));
        $this->line('Subscriptions delta: ' . ($subscriptionCountAfter - $subscriptionCountBefore));

        return self::SUCCESS;
    }
}
