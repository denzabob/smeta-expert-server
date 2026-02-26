<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectProfileRate;
use App\Services\NormohourRateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Контроллер для управления ставками нормо-часов по профилям
 * 
 * API endpoints:
 * POST   /api/projects/{projectId}/profile-rates/calculate - Рассчитать ставку
 * POST   /api/projects/{projectId}/profile-rates            - Создать/обновить ставку
 * GET    /api/projects/{projectId}/profile-rates            - Получить ставки проекта
 * GET    /api/projects/{projectId}/profile-rates/{rateId}   - Получить ставку
 * PATCH  /api/projects/{projectId}/profile-rates/{rateId}   - Обновить ставку
 * DELETE /api/projects/{projectId}/profile-rates/{rateId}   - Удалить ставку
 */
class ProjectProfileRateController extends Controller
{
    private NormohourRateService $rateService;

    public function __construct(NormohourRateService $rateService)
    {
        $this->rateService = $rateService;
    }

    /**
     * Рассчитать ставку без сохранения
     * 
     * POST /api/projects/{projectId}/profile-rates/calculate
     * 
     * Request body:
     * {
     *   "profile_id": 1,
     *   "region_id": 61,        // опционально
     *   "method": "median"      // или "average"
     * }
     */
    public function calculate(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'profile_id' => 'required|integer|exists:position_profiles,id',
                'region_id' => 'nullable|integer|exists:regions,id',
                'method' => 'nullable|string|in:median,average',
            ]);

            $project = Project::findOrFail($projectId);
            
            $calculation = $this->rateService->calculateForProfile(
                projectId: $projectId,
                profileId: $validated['profile_id'],
                regionId: $validated['region_id'] ?? null,
                method: $validated['method'] ?? 'median'
            );

            return response()->json([
                'success' => true,
                'data' => $calculation->toArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('Rate calculation failed', [
                'project_id' => $projectId,
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Создать или обновить ставку с сохранением
     * 
     * POST /api/projects/{projectId}/profile-rates
     * 
     * Request body:
     * {
     *   "profile_id": 1,
     *   "region_id": 61,        // опционально
     *   "method": "median"      // или "average"
     * }
     */
    public function store(Request $request, int $projectId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'profile_id' => 'required|integer|exists:position_profiles,id',
                'region_id' => 'nullable|integer|exists:regions,id',
                'method' => 'nullable|string|in:median,average',
            ]);

            $project = Project::findOrFail($projectId);

            // Проверить, заблокирована ли ставка
            $existing = ProjectProfileRate::where([
                ['project_id', '=', $projectId],
                ['profile_id', '=', $validated['profile_id']],
                ['region_id', '=', $validated['region_id']],
            ])->first();

            if ($existing && $existing->is_locked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Эта ставка заблокирована и не может быть изменена',
                    'locked_reason' => $existing->lock_reason,
                ], Response::HTTP_CONFLICT);
            }

            // Создать/обновить ставку
            $rate = $this->rateService->upsertProjectProfileRate(
                projectId: $projectId,
                profileId: $validated['profile_id'],
                regionId: $validated['region_id'] ?? null,
                method: $validated['method'] ?? 'median'
            );

            return response()->json([
                'success' => true,
                'message' => 'Ставка успешно сохранена',
                'data' => $rate->toArray(),
            ], $existing ? Response::HTTP_OK : Response::HTTP_CREATED);

        } catch (\Exception $e) {
            Log::error('Rate save failed', [
                'project_id' => $projectId,
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Пересчитать ставку по профилю (если не заблокирована)
     * 
     * POST /api/projects/{projectId}/profile-rates/{profileId}/recalculate
     * 
     * Request body:
     * {
     *   "method": "median"  // или "average"
     * }
     * 
     * Response (если заблокирована):
     * 409 Conflict + { is_locked: true, lock_reason: "...", data: {...} }
     * 
     * Response (если обновлена):
     * 200 OK + { success: true, data: {...} }
     */
    public function recalculate(Request $request, int $projectId, int $profileId): JsonResponse
    {
        try {
            $project = Project::findOrFail($projectId);

            $validated = $request->validate([
                'method' => 'nullable|string|in:median,average',
            ]);

            // Получить или создать ставку для профиля
            $rate = ProjectProfileRate::firstWhere([
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'region_id' => $project->region_id,
            ]);

            // Если ставка заблокирована - вернуть 409
            if ($rate && $rate->is_locked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Эта ставка заблокирована и не может быть пересчитана',
                    'is_locked' => true,
                    'lock_reason' => $rate->lock_reason,
                    'data' => $rate->load(['profile', 'region'])->toArray(),
                ], Response::HTTP_CONFLICT);
            }

            // Пересчитать и обновить ставку
            $updatedRate = $this->rateService->upsertProjectProfileRate(
                projectId: $projectId,
                profileId: $profileId,
                regionId: $project->region_id,
                method: $validated['method'] ?? 'median'
            );

            return response()->json([
                'success' => true,
                'message' => 'Ставка успешно пересчитана',
                'data' => $updatedRate->load(['profile', 'region'])->toArray(),
            ]);

        } catch (\Exception $e) {
            Log::error('Rate recalculation failed', [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'request' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить все ставки проекта (оптимизированный список)
     * 
     * GET /api/projects/{projectId}/profile-rates
     * 
     * Returns:
     * {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "profile_id": 5,
     *       "profile_name": "Сборщик мебели",
     *       "region_id": 61,
     *       "region_name": "Свердловская область",
     *       "rate_fixed": 1000.00,
     *       "fixed_at": "2026-01-14T10:30:00.000000Z",
     *       "calculation_method": "median",
     *       "is_locked": false,
     *       "lock_reason": null,
     *       "justification_snapshot": "Расчет ставки...",
     *       "sources_snapshot": [...]
     *     }
     *   ],
     *   "count": 3
     * }
     */
    public function index(int $projectId): JsonResponse
    {
        try {
            $project = Project::findOrFail($projectId);

            $rates = ProjectProfileRate::where('project_id', $projectId)
                ->with(['profile', 'region'])
                ->orderBy('profile_id')
                ->orderBy('region_id')
                ->get()
                ->map(function ($rate) {
                    return [
                        'id' => $rate->id,
                        'profile_id' => $rate->profile_id,
                        'profile_name' => $rate->profile->name ?? null,
                        'region_id' => $rate->region_id,
                        'region_name' => $rate->region->name ?? null,
                        'rate_fixed' => (float) $rate->rate_fixed,
                        'fixed_at' => $rate->fixed_at,
                        'calculation_method' => $rate->calculation_method,
                        'is_locked' => (bool) $rate->is_locked,
                        'lock_reason' => $rate->lock_reason,
                        'justification_snapshot' => $rate->justification_snapshot,
                        'sources_snapshot' => $rate->sources_snapshot ? json_decode($rate->sources_snapshot, true) : [],
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $rates,
                'count' => $rates->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch project rates', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Получить конкретную ставку
     * 
     * GET /api/projects/{projectId}/profile-rates/{rateId}
     */
    public function show(int $projectId, int $rateId): JsonResponse
    {
        try {
            $rate = ProjectProfileRate::where('project_id', $projectId)
                ->with(['profile', 'region', 'project'])
                ->findOrFail($rateId);

            return response()->json([
                'success' => true,
                'data' => $rate,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch rate', [
                'project_id' => $projectId,
                'rate_id' => $rateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ставка не найдена',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Обновить ставку (частичное обновление)
     * 
     * PATCH /api/projects/{projectId}/profile-rates/{rateId}
     * 
     * Request body:
     * {
     *   "is_locked": true,
     *   "lock_reason": "Согласовано с клиентом"
     * }
     */
    public function update(Request $request, int $projectId, int $rateId): JsonResponse
    {
        try {
            $rate = ProjectProfileRate::where('project_id', $projectId)
                ->findOrFail($rateId);

            // Проверить, не заблокирована ли ставка
            if ($rate->is_locked && !$request->has('is_locked')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заблокированную ставку нельзя изменять',
                ], Response::HTTP_CONFLICT);
            }

            $validated = $request->validate([
                'is_locked' => 'nullable|boolean',
                'lock_reason' => 'nullable|string|max:1000',
            ]);

            if ($request->has('is_locked')) {
                $rate->is_locked = $validated['is_locked'];
                if ($validated['is_locked'] && isset($validated['lock_reason'])) {
                    $rate->lock_reason = $validated['lock_reason'];
                }
            }

            $rate->save();

            return response()->json([
                'success' => true,
                'message' => 'Ставка обновлена',
                'data' => $rate,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ставка не найдена',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('Failed to update rate', [
                'project_id' => $projectId,
                'rate_id' => $rateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Удалить ставку
     * 
     * DELETE /api/projects/{projectId}/profile-rates/{rateId}
     */
    public function destroy(int $projectId, int $rateId): JsonResponse
    {
        try {
            $rate = ProjectProfileRate::where('project_id', $projectId)
                ->findOrFail($rateId);

            // Проверить, не заблокирована ли ставка
            if ($rate->is_locked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Заблокированную ставку нельзя удалять',
                ], Response::HTTP_CONFLICT);
            }

            $rate->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ставка удалена',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ставка не найдена',
            ], Response::HTTP_NOT_FOUND);
        } catch (\Exception $e) {
            Log::error('Failed to delete rate', [
                'project_id' => $projectId,
                'rate_id' => $rateId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Получить доступные ставки источников для профиля
     * 
     * GET /api/projects/{projectId}/profile-rates/sources/{profileId}
     */
    public function getSources(int $projectId, int $profileId): JsonResponse
    {
        try {
            $project = Project::findOrFail($projectId);

            // Получить источники для расчета
            $calculation = $this->rateService->calculateForProfile(
                projectId: $projectId,
                profileId: $profileId,
                regionId: $project->region_id
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'sources' => $calculation->sourcesSnapshot,
                    'method' => $calculation->method,
                    'volatility' => $calculation->volatility,
                    'warnings' => $calculation->warnings,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch sources', [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Заблокировать ставки проекта
     * 
     * POST /api/projects/{projectId}/profile-rates/lock
     * 
     * Блокирует все текущие ставки проекта (fixed и non-fixed)
     * Это предотвращает их изменение при следующих пересчетах
     */
    public function lockRates(Request $request, int $projectId): JsonResponse
    {
        try {
            Log::debug('Lock rates request received', ['project_id' => $projectId]);
            
            $project = Project::findOrFail($projectId);
            $this->authorize('update', $project);

            // Способ 1: Получить все ставки проекта (более надежный)
            $rateIds = ProjectProfileRate::where('project_id', $projectId)
                ->pluck('id')
                ->toArray();

            Log::debug('Found rate IDs to lock', ['rate_ids' => $rateIds, 'count' => count($rateIds)]);

            if (empty($rateIds)) {
                // Fallback: может быть ставки находятся в labor works
                $rateIds = \App\Models\ProjectLaborWork::where('project_id', $projectId)
                    ->whereNotNull('project_profile_rate_id')
                    ->distinct('project_profile_rate_id')
                    ->pluck('project_profile_rate_id')
                    ->toArray();
                    
                Log::debug('Found rate IDs from labor works', ['rate_ids' => $rateIds, 'count' => count($rateIds)]);
            }

            if (empty($rateIds)) {
                Log::warning('No rates found to lock', ['project_id' => $projectId]);
                return response()->json([
                    'success' => false,
                    'message' => 'No rates found to lock',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Заблокировать все найденные ставки
            $lockedCount = ProjectProfileRate::whereIn('id', $rateIds)
                ->update([
                    'is_locked' => true,
                    'locked_at' => now(),
                    'locked_reason' => 'Locked by user action',
                ]);

            Log::info('Rates locked', [
                'project_id' => $projectId,
                'locked_count' => $lockedCount,
                'rate_ids' => $rateIds,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'locked_count' => $lockedCount,
                    'rate_ids' => $rateIds,
                ],
                'message' => "Successfully locked {$lockedCount} rates",
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to lock rates', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Разблокировать ставки
     * 
     * POST /api/projects/{projectId}/profile-rates/unlock
     */
    public function unlockRates(Request $request, int $projectId): JsonResponse
    {
        try {
            Log::debug('Unlock rates request received', ['project_id' => $projectId]);
            
            $project = Project::findOrFail($projectId);
            $this->authorize('update', $project);

            // Получить все ставки проекта
            $rateIds = ProjectProfileRate::where('project_id', $projectId)
                ->pluck('id')
                ->toArray();

            Log::debug('Found rate IDs to unlock', ['rate_ids' => $rateIds, 'count' => count($rateIds)]);

            if (empty($rateIds)) {
                Log::warning('No rates found to unlock', ['project_id' => $projectId]);
                return response()->json([
                    'success' => false,
                    'message' => 'No rates found to unlock',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Разблокировать все найденные ставки
            $unlockedCount = ProjectProfileRate::whereIn('id', $rateIds)
                ->update([
                    'is_locked' => false,
                    'locked_at' => null,
                    'locked_reason' => null,
                ]);

            Log::info('Rates unlocked', [
                'project_id' => $projectId,
                'unlocked_count' => $unlockedCount,
                'rate_ids' => $rateIds,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'unlocked_count' => $unlockedCount,
                    'rate_ids' => $rateIds,
                ],
                'message' => "Successfully unlocked {$unlockedCount} rates",
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to unlock rates', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
