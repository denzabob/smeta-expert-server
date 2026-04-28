<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingPayment;
use App\Services\Billing\UserPaymentRefreshService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BillingPaymentRefreshController extends Controller
{
    public function __construct(
        private UserPaymentRefreshService $refreshService,
    ) {}

    public function store(Request $request, BillingPayment $payment): JsonResponse
    {
        try {
            return response()->json($this->refreshService->refresh($request->user(), $payment));
        } catch (RuntimeException $e) {
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
