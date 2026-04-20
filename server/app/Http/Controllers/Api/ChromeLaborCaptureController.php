<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChromeLaborCaptureRequest;
use App\Services\ChromeLaborCaptureService;
use Illuminate\Http\JsonResponse;

class ChromeLaborCaptureController extends Controller
{
    public function __construct(
        private readonly ChromeLaborCaptureService $captureService,
    ) {}

    public function store(StoreChromeLaborCaptureRequest $request): JsonResponse
    {
        $result = $this->captureService->capture(
            $request->user(),
            $request->validated(),
            $request->file('screenshot_file'),
        );

        return response()->json($result, 201);
    }
}
