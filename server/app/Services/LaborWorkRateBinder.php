<?php

namespace App\Services;

use App\Models\ProjectLaborWork;
use App\Models\ProjectProfileRate;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для привязки ставок к работам в проекте
 * 
 * Определяет, какая ставка применяется к каждой работе (position_profile_id)
 * в контексте проекта и региона, и фиксирует эту информацию для прозрачности
 * расчётов и возможности аудита.
 */
class LaborWorkRateBinder
{
    /**
     * Привязать ставку к работе
     * 
     * Логика:
     * 1. Получить регион проекта (projects.region_id)
     * 2. Найти подходящую ставку в project_profile_rates:
     *    - project_id = work.project_id
     *    - profile_id = work.position_profile_id
     *    - region_id = project.region_id (если есть)
     *    - если по региону не найдено → fallback на region_id IS NULL
     * 3. Если ставка найдена:
     *    - Установить project_profile_rate_id
     *    - Скопировать rate_fixed в rate_per_hour
     *    - Рассчитать cost_total = hours × rate_per_hour
     *    - Сохранить rate_snapshot с полной информацией
     * 4. Если ставка не найдена:
     *    - Обнулить поля ставки
     *    - Сохранить rate_snapshot с ошибкой
     *
     * @param ProjectLaborWork $work
     * @return void
     */
    public function bindRate(ProjectLaborWork $work): void
    {
        try {
            // Если position_profile_id не задан, то ставку привязать невозможно
            if (!$work->position_profile_id) {
                Log::warning('LaborWorkRateBinder::bindRate - position_profile_id is null, skipping rate binding', [
                    'project_labor_work_id' => $work->id,
                ]);
                return;
            }

            // Получить проект с регионом
            $project = Project::findOrFail($work->project_id);

            // Найти ставку по проекту, профилю и региону
            $rate = $this->findRate(
                $work->project_id,
                $work->position_profile_id,
                $project->region_id
            );

            if ($rate) {
                // Ставка найдена - применить её
                $this->applyRate($work, $rate, $project);
            } else {
                // Ставка не найдена - установить NULL и ошибку
                $this->setNoRate($work, $project);
            }

            // Сохранить изменения
            $work->save();

            Log::info('Ставка привязана к работе', [
                'project_labor_work_id' => $work->id,
                'rate_per_hour' => $work->rate_per_hour,
                'cost_total' => $work->cost_total,
                'rate_found' => $rate ? true : false,
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка при привязке ставки к работе', [
                'project_labor_work_id' => $work->id,
                'error' => $e->getMessage(),
            ]);

            // Установить ошибку в rate_snapshot
            $work->project_profile_rate_id = null;
            $work->rate_per_hour = null;
            $work->cost_total = null;
            $work->rate_snapshot = json_encode([
                'error' => 'Failed to bind rate',
                'message' => $e->getMessage(),
                'error_at' => Carbon::now()->toIso8601String(),
            ]);
            $work->save();
        }
    }

    /**
     * Найти подходящую ставку для работы
     * 
     * Стратегия поиска:
     * 1. Сначала ищем по точному регионам (project.region_id)
     * 2. Если не найдено, ищем с region_id IS NULL (без привязки к региону)
     *
     * @param int $projectId
     * @param int $positionProfileId
     * @param int|null $regionId
     * @return ProjectProfileRate|null
     */
    private function findRate(int $projectId, int $positionProfileId, ?int $regionId): ?ProjectProfileRate
    {
        // Попытка 1: Найти по точному региону
        if ($regionId) {
            $rate = ProjectProfileRate::where('project_id', $projectId)
                ->where('profile_id', $positionProfileId)
                ->where('region_id', $regionId)
                ->first();

            if ($rate) {
                return $rate;
            }
        }

        // Попытка 2: Fallback на ставку без привязки к региону
        $rate = ProjectProfileRate::where('project_id', $projectId)
            ->where('profile_id', $positionProfileId)
            ->whereNull('region_id')
            ->first();

        return $rate;
    }

    /**
     * Применить найденную ставку к работе
     *
     * @param ProjectLaborWork $work
     * @param ProjectProfileRate $rate
     * @param Project $project
     * @return void
     */
    private function applyRate(ProjectLaborWork $work, ProjectProfileRate $rate, Project $project): void
    {
        // Установить связь на ставку
        $work->project_profile_rate_id = $rate->id;

        // Копировать ставку руб/час
        $work->rate_per_hour = $rate->rate_fixed;

        // Рассчитать итоговую стоимость
        $work->cost_total = round(floatval($work->hours) * floatval($rate->rate_fixed), 2);

        // Создать снимок ставки для отчётов и аудита
        $work->rate_snapshot = json_encode([
            'rate_fixed' => $rate->rate_fixed,
            'fixed_at' => $rate->fixed_at?->toIso8601String(),
            'calculation_method' => $rate->calculation_method ?? 'manual',
            'sources_snapshot' => $this->extractSourcesSnapshot($rate),
            'justification_snapshot' => $rate->justification ?? null,
            'applied_at' => Carbon::now()->toIso8601String(),
            'rate_id' => $rate->id,
            'region_id' => $rate->region_id,
            'position_profile_id' => $rate->profile_id,
        ]);
    }

    /**
     * Установить статус "ставка не найдена"
     *
     * @param ProjectLaborWork $work
     * @param Project $project
     * @return void
     */
    private function setNoRate(ProjectLaborWork $work, Project $project): void
    {
        // Обнулить поля ставки
        $work->project_profile_rate_id = null;
        $work->rate_per_hour = null;
        $work->cost_total = null;

        // Установить ошибку в rate_snapshot
        $work->rate_snapshot = json_encode([
            'error' => 'Rate not set for profile',
            'error_message' => 'No suitable rate found',
            'details' => [
                'project_id' => $work->project_id,
                'position_profile_id' => $work->position_profile_id,
                'region_id' => $project->region_id,
                'search_fallback' => 'Checked both with region_id and without (IS NULL)',
            ],
            'error_at' => Carbon::now()->toIso8601String(),
        ]);
    }

    /**
     * Извлечь информацию об источниках из ставки
     * 
     * Для обоснования и прозрачности расчётов
     *
     * @param ProjectProfileRate $rate
     * @return array|null
     */
    private function extractSourcesSnapshot(ProjectProfileRate $rate): ?array
    {
        // Если в ставке уже есть sources_snapshot - использовать его
        if ($rate->sources_snapshot) {
            $snapshot = json_decode($rate->sources_snapshot, true);
            if (is_array($snapshot)) {
                return $snapshot;
            }
        }

        // Иначе создать базовую информацию о расчёте
        return [
            'min_sources' => $rate->min_sources ?? null,
            'max_sources' => $rate->max_sources ?? null,
            'calculation_type' => $rate->calculation_method ?? 'manual',
        ];
    }

    /**
     * Привязать ставки для всех работ в проекте
     * 
     * Может быть использовано для массового переопределения ставок
     * или при изменении ставок в проекте
     *
     * @param int $projectId
     * @return array
     */
    public function bindRatesForProject(int $projectId): array
    {
        $works = ProjectLaborWork::where('project_id', $projectId)->get();

        $results = [
            'total' => $works->count(),
            'bound' => 0,
            'failed' => 0,
        ];

        foreach ($works as $work) {
            try {
                $this->bindRate($work);
                $results['bound']++;
            } catch (\Exception $e) {
                $results['failed']++;
                Log::error('Ошибка при привязке ставки к работе в проекте', [
                    'project_id' => $projectId,
                    'work_id' => $work->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Привязка ставок для проекта завершена', $results);

        return $results;
    }

    /**
     * Обновить ставки для работ при изменении ставки в проекте
     * 
     * Используется когда ставка в project_profile_rates изменяется
     * и нужно пересчитать стоимость всех связанных работ
     *
     * @param ProjectProfileRate $rate
     * @return array
     */
    public function rebindWorksForRate(ProjectProfileRate $rate): array
    {
        $works = ProjectLaborWork::where('project_id', $rate->project_id)
            ->where('position_profile_id', $rate->profile_id)
            ->get();

        $results = [
            'total' => $works->count(),
            'rebound' => 0,
            'failed' => 0,
        ];

        foreach ($works as $work) {
            try {
                $this->bindRate($work);
                $results['rebound']++;
            } catch (\Exception $e) {
                $results['failed']++;
                Log::error('Ошибка при перепривязке ставки', [
                    'rate_id' => $rate->id,
                    'work_id' => $work->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Перепривязка ставок завершена', $results);

        return $results;
    }
}
