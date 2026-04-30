<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\UserPaymentResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingPaymentResultController extends Controller
{
    public function __construct(
        private UserPaymentResultService $paymentResultService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->paymentResultService->result(
            $request->user(),
            isset($validated['invoice_id']) ? (int) $validated['invoice_id'] : null,
        ));
    }
}
