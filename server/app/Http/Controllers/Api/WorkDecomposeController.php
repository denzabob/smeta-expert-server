<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecomposeWorkRequest;
use App\Http\Requests\WorkPresetFeedbackRequest;
use App\Models\WorkPreset;
use App\Services\AI\DecompositionService;
use App\Services\AI\FeedbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WorkDecomposeController extends Controller
{
    public function __construct(
        private DecompositionService $decompositionService,
        private FeedbackService $feedbackService
    ) {}

    /**
     * POST /api/work/decompose
     * 
     * Получить предложение по декомпозиции работы на этапы
     */
    public function decompose(DecomposeWorkRequest $request): JsonResponse
    {
        try {
            $result = $this->decompositionService->suggest(
                title: $request->validated('title'),
                context: $request->validated('context', []),
                desiredHours: $request->validated('desired_hours'),
                note: $request->validated('note')
            );
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Work decomposition failed', [
                'title' => $request->validated('title'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'error' => 'Не удалось выполнить декомпозицию работы',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/work/presets/feedback
     * 
     * Сохранить обратную связь по финальным этапам для накопления пресетов
     */
    public function feedback(WorkPresetFeedbackRequest $request): JsonResponse
    {
        try {
            $source = $request->validated('source', 'manual') === 'ai' 
                ? WorkPreset::SOURCE_AI 
                : WorkPreset::SOURCE_MANUAL;
            
            $this->feedbackService->capture(
                title: $request->validated('title'),
                context: $request->validated('context', []),
                finalSteps: $request->validated('steps'),
                source: $source
            );
            
            return response()->json(['ok' => true], 200);
            
        } catch (\Exception $e) {
            Log::error('Work preset feedback failed', [
                'title' => $request->validated('title'),
                'error' => $e->getMessage(),
            ]);
            
            // Молча глотаем ошибку - feedback не должен ломать основной flow
            return response()->json(['ok' => true], 200);
        }
    }
}
