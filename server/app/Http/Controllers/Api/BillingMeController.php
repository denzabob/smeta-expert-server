<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\UserBillingPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingMeController extends Controller
{
    public function __invoke(Request $request, UserBillingPreviewService $service): JsonResponse
    {
        if (! (bool) config('billing.user_ui_enabled', false)) {
            abort(404);
        }

        return response()->json($service->preview($request->user()));
    }
}
