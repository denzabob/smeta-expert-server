<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectLaborWork;
use App\Services\LaborWorkRateBinder;
use App\Services\ProjectProfileRateResolver;
use App\Services\LaborWorksRecalculator;
use App\Services\ProfileRateFixerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaborWorkRateController extends Controller
{
    protected LaborWorkRateBinder $rateBinder;
    protected ProjectProfileRateResolver $rateResolver;
    protected LaborWorksRecalculator $recalculator;
    protected ProfileRateFixerService $rateFixerService;

    public function __construct(
        LaborWorkRateBinder $rateBinder,
        ProjectProfileRateResolver $rateResolver,
        LaborWorksRecalculator $recalculator,
        ProfileRateFixerService $rateFixerService
    ) {
        $this->rateBinder = $rateBinder;
        $this->rateResolver = $rateResolver;
        $this->recalculator = $recalculator;
        $this->rateFixerService = $rateFixerService;
    }

    /**
     * Привязать ставку к одной работе
     * 
     * POST /api/project-labor-works/{id}/bind-rate
     */
    public function bindRate(int $id): JsonResponse
    {
        try {
            $work = ProjectLaborWork::findOrFail($id);

            // Привязать ставку
            $this->rateBinder->bindRate($work);

            return response()->json([
                'success' => true,
                'message' => 'Ставка успешно привязана к работе',
                'data' => [
                    'id' => $work->id,
                    'rate_per_hour' => $work->rate_per_hour,
                    'cost_total' => $work->cost_total,
                    'has_error' => $work->hasRateError(),
                    'error_message' => $work->getRateErrorMessage(),
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Работа не найдена',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка привязки ставки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Привязать ставки для всех работ в проекте
     * 
     * POST /api/projects/{projectId}/bind-labor-work-rates
     */
    public function bindRatesForProject(int $projectId): JsonResponse
    {
        try {
            $results = $this->rateBinder->bindRatesForProject($projectId);

            return response()->json([
                'success' => true,
                'message' => 'Ставки привязаны к работам проекта',
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при привязке ставок',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить информацию о привязанной ставке к работе
     * 
     * GET /api/project-labor-works/{id}/rate-info
     */
    public function getRateInfo(int $id): JsonResponse
    {
        try {
            $work = ProjectLaborWork::with('profileRate')
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $work->id,
                    'title' => $work->title,
                    'hours' => $work->hours,
                    'rate_per_hour' => $work->rate_per_hour,
                    'cost_total' => $work->cost_total,
                    'has_rate' => $work->rate_per_hour !== null,
                    'has_error' => $work->hasRateError(),
                    'error_message' => $work->getRateErrorMessage(),
                    'rate_snapshot' => $work->rate_snapshot,
                    'profile_rate' => $work->profileRate ? [
                        'id' => $work->profileRate->id,
                        'rate_fixed' => $work->profileRate->rate_fixed,
                        'calculation_method' => $work->profileRate->calculation_method,
                        'fixed_at' => $work->profileRate->fixed_at,
                    ] : null,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Работа не найдена',
            ], 404);
        }
    }

    /**
     * Пересчитать ставки для всех работ проекта
     * 
     * POST /api/projects/{projectId}/recalculate-labor-rates
     * Тело: { mode: 'preview' | 'fix' }
     */
    public function recalculateLaborRates(int $projectId, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'mode' => 'required|in:preview,fix',
            ]);

            $mode = $validated['mode'];
            $result = $this->recalculator->recalculateLaborWorks($projectId, $mode);

            return response()->json([
                'success' => $result->success,
                'message' => $result->message,
                'data' => [
                    'mode' => $result->mode,
                    'updated_count' => $result->updated_count,
                    'error_count' => $result->error_count,
                    'errors' => $result->errors,
                    'updated_works' => $result->updated_works,
                ],
            ], $result->success ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пересчете ставок',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить эффективную ставку для профиля (для отладки)
     * 
     * GET /api/projects/{projectId}/profiles/{profileId}/effective-rate
     * Query: ?region_id=123
     */
    public function getEffectiveRate(int $projectId, int $profileId, Request $request): JsonResponse
    {
        try {
            $regionId = $request->query('region_id');
            $rateDTO = $this->rateResolver->resolveEffectiveRate($projectId, $profileId, $regionId);

            return response()->json([
                'success' => true,
                'data' => [
                    'rate_per_hour' => $rateDTO->rate_per_hour,
                    'rate_source' => $rateDTO->rate_source,
                    'project_profile_rate_id' => $rateDTO->project_profile_rate_id,
                    'sources_snapshot' => $rateDTO->sources_snapshot,
                    'justification_snapshot' => $rateDTO->justification_snapshot,
                    'rate_snapshot' => $rateDTO->rate_snapshot,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения ставки',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Автопересчет ставок на загрузке страницы (preview mode)
     * 
     * POST /api/projects/{projectId}/labor-works/recalculate
     * Body: { mode: 'preview' } или пусто
     * 
     * Возвращает работы с заполненными rate_per_hour и cost_total
     */
    public function recalculateLaborWorksAuto(int $projectId, Request $request): JsonResponse
    {
        try {
            // Mode опционален, по умолчанию preview
            $mode = $request->input('mode', 'preview');
            if (!in_array($mode, ['preview', 'fix'])) {
                $mode = 'preview';
            }

            // Попробовать пересчитать ставки
            $result = null;
            try {
                $result = $this->recalculator->recalculateLaborWorks($projectId, 'preview');
            } catch (\Exception $e) {
                // Если ошибка в пересчете, просто логируем и продолжаем
                \Log::warning('Labor works recalculation error', [
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Собрать информацию о недостающих ставках
            $allWorks = ProjectLaborWork::where('project_id', $projectId)->orderBy('sort_order')->get();
            $hasMissingRates = $allWorks->count() > 0 && $allWorks->contains(fn($work) => $work->rate_per_hour === null);

            return response()->json([
                'success' => true,
                'message' => 'Labor works loaded',
                'data' => [
                    'mode' => 'preview',
                    'updated_count' => $result ? $result->updated_count : 0,
                    'error_count' => $result ? $result->error_count : 0,
                    'has_missing_rates' => $hasMissingRates,
                    'works' => $allWorks->map(fn($work) => [
                        'id' => $work->id,
                        'title' => $work->title,
                        'basis' => $work->basis,
                        'hours' => $work->hours,
                        'hours_source' => $work->hours_source,
                        'hours_manual' => $work->hours_manual,
                        'note' => $work->note,
                        'position_profile_id' => $work->position_profile_id,
                        'sort_order' => $work->sort_order,
                        'rate_per_hour' => $work->rate_per_hour,
                        'cost_total' => $work->cost_total,
                        'rate_snapshot' => $work->rate_snapshot,
                        'rate_source' => $work->rate_snapshot ? 
                            (is_array($work->rate_snapshot) ? ($work->rate_snapshot['method'] ?? null) : json_decode($work->rate_snapshot, true)['method'] ?? null)
                            : null,
                    ])->toArray(),
                    'errors' => $result ? $result->errors : null,
                ],
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Labor works auto recalculation error', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error loading labor works',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Пересчет и фиксация ставок (ручной пересчет с кнопки)
     * 
     * POST /api/projects/{projectId}/profile-rates/recalculate-and-fix
     * Body: {
     *   method: 'median' | 'average',
     *   only_if_missing: false
     * }
     * 
     * Логика:
     * 1. Для каждого профиля из project_labor_works
     * 2. Посчитать ставку из global_normohour_sources (если нужно)
     * 3. Создать новую запись в project_profile_rates с is_locked=1
     * 4. Пересчитать project_labor_works в режиме preview (они получат project_profile_rate_id)
     * 5. Вернуть обновленные работы + созданные rate ids
     */
    public function recalculateAndFixRates(int $projectId, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'method' => 'required|in:median,average',
                'only_if_missing' => 'sometimes|boolean',
            ]);

            $method = $validated['method'];
            $onlyIfMissing = $validated['only_if_missing'] ?? false;

            // Выполнить пересчет и фиксацию ставок
            $result = $this->rateFixerService->recalculateAndFixRates(
                $projectId,
                $method,
                $onlyIfMissing
            );

            // Получить обновленные работы для возврата
            $updatedWorks = ProjectLaborWork::where('project_id', $projectId)
                ->get()
                ->map(fn($work) => [
                    'id' => $work->id,
                    'title' => $work->title,
                    'hours' => $work->hours,
                    'rate_per_hour' => $work->rate_per_hour,
                    'cost_total' => $work->cost_total,
                    'project_profile_rate_id' => $work->project_profile_rate_id,
                    'rate_source' => $work->rate_snapshot ? 
                        (is_array($work->rate_snapshot) ? ($work->rate_snapshot['method'] ?? null) : json_decode($work->rate_snapshot, true)['method'] ?? null)
                        : null,
                ])
                ->toArray();

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'method' => $method,
                    'only_if_missing' => $onlyIfMissing,
                    'created_rate_ids' => $result['created_rate_ids'],
                    'updated_count' => $result['updated_count'],
                    'error_count' => $result['error_count'],
                    'errors' => $result['errors'],
                    'updated_works' => $updatedWorks,
                ],
            ], $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при фиксации ставок',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

