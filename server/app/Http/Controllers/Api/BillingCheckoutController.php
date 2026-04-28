<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\UserCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BillingCheckoutController extends Controller
{
    public function __construct(
        private UserCheckoutService $checkoutService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:100'],
        ]);

        try {
            $checkout = $this->checkoutService->createCheckout(
                $request->user(),
                $validated['plan_code'],
            );
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json($checkout, 201);
    }
}
