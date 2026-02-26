<?php

namespace App\Services;

use App\Models\ProjectLaborWork;
use App\Models\ProjectProfileRate;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DTO результата пересчета
 */
class RecalculationResult
{
    public bool $success;
    public string $mode; // 'preview' или 'fix'
    public int $updated_count;
    public int $error_count;
    public ?array $errors;
    public ?array $updated_works;
    public string $message;

    public function __construct(
        bool $success,
        string $mode,
        int $updated_count = 0,
        int $error_count = 0,
        ?array $errors = null,
        ?array $updated_works = null,
        string $message = ''
    ) {
        $this->success = $success;
        $this->mode = $mode;
        $this->updated_count = $updated_count;
        $this->error_count = $error_count;
        $this->errors = $errors;
        $this->updated_works = $updated_works;
        $this->message = $message;
    }
}

/**
 * Сервис для пересчета ставок и стоимостей монтажно-сборочных работ
 * 
 * Поддерживает два режима:
 * - preview: пересчитывает rate_per_hour/cost_total на основе текущих ставок, НЕ создает project_profile_rates
 * - fix: создает новые записи в project_profile_rates, затем обновляет project_labor_works
 */
class LaborWorksRecalculator
{
    protected ProjectProfileRateResolver $rateResolver;

    public function __construct(ProjectProfileRateResolver $rateResolver)
    {
        $this->rateResolver = $rateResolver;
    }

    /**
     * Пересчитать ставки и стоимости работ
     * 
     * @param int $projectId ID проекта
     * @param string $mode 'preview' или 'fix'
     * @return RecalculationResult
     */
    public function recalculateLaborWorks(int $projectId, string $mode = 'preview'): RecalculationResult
    {
        try {
            if (!in_array($mode, ['preview', 'fix'])) {
                throw new \InvalidArgumentException("Invalid mode: {$mode}. Must be 'preview' or 'fix'.");
            }

            // Получить проект
            $project = Project::findOrFail($projectId);

            // Получить все работы проекта
            $allWorks = ProjectLaborWork::where('project_id', $projectId)
                ->get();

            if ($allWorks->isEmpty()) {
                return new RecalculationResult(
                    success: true,
                    mode: $mode,
                    updated_count: 0,
                    message: 'No labor works found for project'
                );
            }

            // Собрать уникальные position_profile_id
            $uniqueProfileIds = $allWorks->pluck('position_profile_id')->unique()->filter()->values()->toArray();

            if (empty($uniqueProfileIds)) {
                return new RecalculationResult(
                    success: true,
                    mode: $mode,
                    updated_count: 0,
                    message: 'No position profiles found in labor works'
                );
            }

            $errors = [];
            $updatedWorks = [];
            $createdRates = []; // Для tracking созданных ставок в режиме 'fix'

            // Для каждого профиля получить effective rate
            foreach ($uniqueProfileIds as $profileId) {
                try {
                    // Получить эффективную ставку
                    // В режиме preview НЕ игнорируем заблокированные ставки - уважаем их
                    // forcePreview используется только при фиксации (fix режим)
                    // Это позволяет locked ставкам оставаться заблокированными
                    $forcePreview = false;
                    
                    $rateDTO = $this->rateResolver->resolveEffectiveRate(
                        $projectId,
                        $profileId,
                        $project->region_id,
                        $forcePreview
                    );

                    // В режиме 'fix' - создать запись в project_profile_rates если её нет
                    $rateId = null;
                    if ($mode === 'fix' && $rateDTO->rate_source === 'preview') {
                        $rateId = $this->createFixedRate($projectId, $profileId, $rateDTO, $project->region_id);
                        $createdRates[] = $rateId;
                    } else if ($rateDTO->project_profile_rate_id) {
                        $rateId = $rateDTO->project_profile_rate_id;
                    }

                    // Обновить все работы этого профиля
                    $profileWorks = $allWorks->where('position_profile_id', $profileId);
                    foreach ($profileWorks as $work) {
                        // Рассчитать новую стоимость
                        $newCost = round(floatval($work->hours) * $rateDTO->rate_per_hour, 2);
                        
                        // Логирование для отладки
                        $oldRate = $work->rate_per_hour;
                        $oldCost = $work->cost_total;
                        
                        Log::debug('Recalculating labor work rate', [
                            'work_id' => $work->id,
                            'work_title' => $work->title,
                            'profile_id' => $profileId,
                            'mode' => $mode,
                            'force_preview' => $forcePreview,
                            'old_rate' => $oldRate,
                            'new_rate' => $rateDTO->rate_per_hour,
                            'old_cost' => $oldCost,
                            'new_cost' => $newCost,
                            'rate_source' => $rateDTO->rate_source,
                        ]);

                        // Обновить работу в БД
                        $work->rate_per_hour = $rateDTO->rate_per_hour;
                        $work->cost_total = $newCost;
                        $work->project_profile_rate_id = $rateId;
                        $work->rate_snapshot = json_encode([
                            'method' => $rateDTO->rate_source,
                            'rate_per_hour' => $rateDTO->rate_per_hour,
                            'sources_snapshot' => $rateDTO->sources_snapshot ? json_decode($rateDTO->sources_snapshot, true) : null,
                            'justification_snapshot' => $rateDTO->justification_snapshot,
                            'rate_fixed' => $rateDTO->rate_snapshot['rate_fixed'] ?? $rateDTO->rate_per_hour,
                            'calculated_at' => Carbon::now()->toIso8601String(),
                        ]);
                        $work->save();

                        $updatedWorks[] = [
                            'id' => $work->id,
                            'title' => $work->title,
                            'basis' => $work->basis,
                            'hours' => $work->hours,
                            'rate_per_hour' => $rateDTO->rate_per_hour,
                            'cost_total' => $newCost,
                            'rate_source' => $rateDTO->rate_source,
                        ];
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'profile_id' => $profileId,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Error recalculating labor works for profile', [
                        'project_id' => $projectId,
                        'profile_id' => $profileId,
                        'mode' => $mode,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $result = new RecalculationResult(
                success: count($errors) === 0,
                mode: $mode,
                updated_count: count($updatedWorks),
                error_count: count($errors),
                errors: $errors ?: null,
                updated_works: $updatedWorks,
                message: "Successfully recalculated {$mode} rates for " . count($updatedWorks) . " labor works"
                    . ($mode === 'fix' ? " and created " . count($createdRates) . " fixed rates" : "")
            );

            Log::info('Labor works recalculation completed', [
                'project_id' => $projectId,
                'mode' => $mode,
                'updated_count' => count($updatedWorks),
                'error_count' => count($errors),
                'created_rates' => count($createdRates),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('Error in recalculateLaborWorks', [
                'project_id' => $projectId,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return new RecalculationResult(
                success: false,
                mode: $mode,
                error_count: 1,
                errors: [['error' => $e->getMessage()]],
                message: 'Error recalculating labor works: ' . $e->getMessage()
            );
        }
    }

    /**
     * Создать фиксированную ставку из preview
     * 
     * @param int $projectId
     * @param int $profileId
     * @param RateDTO $rateDTO
     * @param int|null $regionId
     * @return int ID созданной ставки
     */
    private function createFixedRate(int $projectId, int $profileId, RateDTO $rateDTO, ?int $regionId): int
    {
        $rate = ProjectProfileRate::create([
            'project_id' => $projectId,
            'profile_id' => $profileId,
            'region_id' => $regionId,
            'rate_fixed' => $rateDTO->rate_per_hour,
            'fixed_at' => Carbon::now(),
            'is_locked' => false,
            'calculation_method' => $rateDTO->rate_source,
            'sources_snapshot' => $rateDTO->sources_snapshot,
            'justification_snapshot' => $rateDTO->justification_snapshot,
        ]);

        return $rate->id;
    }
}
