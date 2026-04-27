<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\Payments\BillingPaymentService;
use App\Services\Billing\Payments\PaymentProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class BillingWebhookController extends Controller
{
    public function __construct(
        private BillingPaymentService $paymentService,
        private PaymentProviderManager $providerManager,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        try {
            $this->providerManager->driver($provider);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        try {
            $event = $this->paymentService->handleProviderWebhook(
                $provider,
                $request->json()->all(),
                $request->headers->all(),
            );

            return response()->json([
                'status' => $event->processing_status,
            ]);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Webhook processing failed.',
            ], 500);
        }
    }
}
