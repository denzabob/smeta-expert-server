<?php

namespace App\Services;

use App\Models\ProjectLaborWork;
use App\Models\ProjectProfileRate;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для пересчета и фиксации ставок профилей
 * 
 * Логика:
 * 1. Собрать все уникальные position_profile_id из project_labor_works
 * 2. Для каждого профиля получить effective rate (preview из LaborWorksRecalculator)
 * 3. Создать новую запись в project_profile_rates с is_locked=1
 * 4. Пересчитать project_labor_works в режиме preview (они получат project_profile_rate_id)
 */
class ProfileRateFixerService
{
    protected ProjectProfileRateResolver $rateResolver;
    protected LaborWorksRecalculator $recalculator;

    public function __construct(
        ProjectProfileRateResolver $rateResolver,
        LaborWorksRecalculator $recalculator
    ) {
        $this->rateResolver = $rateResolver;
        $this->recalculator = $recalculator;
    }

    /**
     * Пересчитать и зафиксировать ставки для проекта
     * 
     * @param int $projectId ID проекта
     * @param string $method 'median' или 'average'
     * @param bool $onlyIfMissing Пересчитать только если нет locked rate
     * @return array Результат с информацией о созданных ставках
     */
    public function recalculateAndFixRates(int $projectId, string $method = 'median', bool $onlyIfMissing = false): array
    {
        try {
            // Получить проект
            $project = Project::findOrFail($projectId);

            // Получить все работы проекта
            $allWorks = ProjectLaborWork::where('project_id', $projectId)->get();

            if ($allWorks->isEmpty()) {
                return [
                    'success' => true,
                    'message' => 'No labor works found',
                    'created_rate_ids' => [],
                    'updated_count' => 0,
                ];
            }

            // Собрать уникальные position_profile_id
            $uniqueProfileIds = $allWorks->pluck('position_profile_id')
                ->unique()
                ->filter()
                ->values()
                ->toArray();

            $createdRateIds = [];
            $errors = [];

            // Для каждого профиля: создать/обновить ставку и зафиксировать её
            foreach ($uniqueProfileIds as $profileId) {
                try {
                    // Проверить наличие locked rate если onlyIfMissing=true
                    $existingLockedRate = ProjectProfileRate::where('project_id', $projectId)
                        ->where('profile_id', $profileId)
                        ->where('is_locked', true)
                        ->first();

                    if ($onlyIfMissing && $existingLockedRate) {
                        // Пропустить этот профиль
                        Log::info('Skipping profile - locked rate exists', [
                            'project_id' => $projectId,
                            'profile_id' => $profileId,
                        ]);
                        continue;
                    }

                    // Получить effective rate для этого профиля (это посчитает preview если нужно)
                    // forcePreview=true чтобы всегда пересчитывать из источников (не использовать старые locked rate)
                    $rateDTO = $this->rateResolver->resolveEffectiveRate(
                        $projectId,
                        $profileId,
                        $project->region_id,
                        true  // forcePreview=true
                    );

                    // Создать или обновить запись в project_profile_rates с is_locked=1
                    $fixedRate = ProjectProfileRate::updateOrCreate(
                        [
                            'project_id' => $projectId,
                            'profile_id' => $profileId,
                            'region_id' => $project->region_id,
                        ],
                        [
                            'rate_fixed' => $rateDTO->rate_per_hour,
                            'fixed_at' => Carbon::now(),
                            'is_locked' => 1,  // ФИКСИРУЕМ!
                            'locked_at' => Carbon::now(),
                            'locked_reason' => 'Зафиксировано пользователем вручную',
                            'calculation_method' => 'median',  // Всегда медиана при фиксировании (из ProjectProfileRateResolver)
                            'sources_snapshot' => $rateDTO->sources_snapshot,
                            'justification_snapshot' => $rateDTO->justification_snapshot,
                        ]
                    );

                    $createdRateIds[] = $fixedRate->id;

                    Log::info('Fixed rate created', [
                        'project_id' => $projectId,
                        'profile_id' => $profileId,
                        'rate_id' => $fixedRate->id,
                        'rate_fixed' => $fixedRate->rate_fixed,
                    ]);

                } catch (\Exception $e) {
                    $errors[] = [
                        'profile_id' => $profileId,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Error creating fixed rate', [
                        'project_id' => $projectId,
                        'profile_id' => $profileId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // После создания всех ставок - пересчитать project_labor_works в preview mode
            // Это заполнит project_profile_rate_id для всех работ
            $recalculationResult = $this->recalculator->recalculateLaborWorks($projectId, 'preview');

            return [
                'success' => count($errors) === 0,
                'message' => 'Successfully fixed ' . count($createdRateIds) . ' rates and recalculated ' . $recalculationResult->updated_count . ' works',
                'created_rate_ids' => $createdRateIds,
                'updated_count' => $recalculationResult->updated_count,
                'error_count' => count($errors),
                'errors' => $errors ?: null,
            ];

        } catch (\Exception $e) {
            Log::error('Error in recalculateAndFixRates', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error fixing rates: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'created_rate_ids' => [],
                'updated_count' => 0,
            ];
        }
    }
}
