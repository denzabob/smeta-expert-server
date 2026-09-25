<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Services\Expert\ExpertStorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StorageController extends Controller
{
    public function __invoke(Request $request, ExpertStorageQuotaService $quota): JsonResponse
    {
        return response()->json($quota->snapshot($request->user())->toStorageArray());
    }
}
