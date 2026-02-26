<?php

namespace App\Services;

use App\Models\ProjectProfileRate;
use App\Models\Project;
use App\Models\PositionProfile;
use App\Models\GlobalNormohourSource;
use App\Services\RateModelCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * DTO для эффективной ставки
 */
class RateDTO
{
    public float $rate_per_hour;
    public string $rate_source; // 'locked', 'fixed', 'preview', 'none', 'error'
    public ?int $project_profile_rate_id;
    public ?string $sources_snapshot;
    public ?string $justification_snapshot;
    public ?array $rate_snapshot;

    public function __construct(
        float $rate_per_hour,
        string $rate_source,
        ?int $project_profile_rate_id = null,
        ?string $sources_snapshot = null,
        ?string $justification_snapshot = null,
        ?array $rate_snapshot = null
    ) {
        $this->rate_per_hour = $rate_per_hour;
        $this->rate_source = $rate_source;
        $this->project_profile_rate_id = $project_profile_rate_id;
        $this->sources_snapshot = $sources_snapshot;
        $this->justification_snapshot = $justification_snapshot;
        $this->rate_snapshot = $rate_snapshot;
    }
}

/**
 * Сервис для получения эффективной ставки (effective rate) для профиля в проекте
 * 
 * Правило выбора ставки (приоритет):
 * 1. Если есть последняя запись в project_profile_rates с is_locked=1 → locked rate
 * 2. Иначе если есть последняя запись (не locked) → fixed rate
 * 3. Иначе → preview rate (считается на лету из global_normohour_sources)
 */
class ProjectProfileRateResolver
{

    private RateModelCalculator $rateModelCalculator;

    public function __construct(?RateModelCalculator $rateModelCalculator = null)
    {
        $this->rateModelCalculator = $rateModelCalculator ?? new RateModelCalculator();
    }

    /**
     * Преобразовать значение в JSON string если это array, иначе return как есть
     */
    private function ensureJsonString(string|array|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return $value;
    }

    /**
     * Безопасное преобразование дать в ISO8601 string
     * Обрабатывает как Carbon objects, так и string даты
     */
    private function toIso8601String($value): ?string
    {
        if ($value === null) {
            return null;
        }
        
        // Если это уже string - возвращаем как есть
        if (is_string($value)) {
            return $value;
        }
        
        // Если это Carbon/DateTime - преобразуем в ISO8601
        if (method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }
        
        // Fallback
        return (string)$value;
    }

    /**
     * Получить эффективную ставку для профиля в проекте
     * 
     * @param int $projectId ID проекта
     * @param int $profileId ID профиля (position_profile_id)
     * @param int|null $regionId ID региона (если null, fallback на region_id проекта или NULL)
     * @param bool $forcePreview Если true, игнорирует locked/fixed ставки и пересчитывает из sources
     * @return RateDTO
     */
    public function resolveEffectiveRate(int $projectId, int $profileId, ?int $regionId = null, bool $forcePreview = false): RateDTO
    {
        try {
            // Получить проект для региона
            $project = Project::findOrFail($projectId);
            
            // Использовать регион из параметра или из проекта
            if ($regionId === null) {
                $regionId = $project->region_id;
            }

            Log::debug('ProjectProfileRateResolver::resolveEffectiveRate', [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'region_id' => $regionId,
                'force_preview' => $forcePreview,
            ]);

            // Если требуется preview - пропустить locked/fixed и считать из sources
            if (!$forcePreview) {
                // Попытка 1: Найти последнюю locked ставку
                $lockedRate = $this->findLockedRate($projectId, $profileId, $regionId);
                if ($lockedRate) {
                    Log::debug('ProjectProfileRateResolver::resolveEffectiveRate - Found locked rate', [
                        'rate_id' => $lockedRate->id,
                        'rate_fixed' => $lockedRate->rate_fixed,
                        'is_locked' => $lockedRate->is_locked,
                    ]);
                    return new RateDTO(
                        rate_per_hour: (float)$lockedRate->rate_fixed,
                        rate_source: 'locked',
                        project_profile_rate_id: $lockedRate->id,
                        sources_snapshot: $this->ensureJsonString($lockedRate->sources_snapshot),
                        justification_snapshot: $this->ensureJsonString($lockedRate->justification_snapshot),
                        rate_snapshot: [
                            'type' => 'locked',
                            'rate_fixed' => (float)$lockedRate->rate_fixed,
                            'fixed_at' => $this->toIso8601String($lockedRate->fixed_at),
                            'is_locked' => true,
                            'locked_at' => $this->toIso8601String($lockedRate->locked_at),
                            'locked_reason' => $lockedRate->locked_reason,
                        ]
                    );
                }
            }

            // Попытка 2: Найти последнюю fixed (не locked) ставку (только если не forcePreview)
            if (!$forcePreview) {
                $fixedRate = $this->findFixedRate($projectId, $profileId, $regionId);
                if ($fixedRate) {
                    return new RateDTO(
                        rate_per_hour: (float)$fixedRate->rate_fixed,
                        rate_source: 'fixed',
                        project_profile_rate_id: $fixedRate->id,
                        sources_snapshot: $this->ensureJsonString($fixedRate->sources_snapshot),
                        justification_snapshot: $this->ensureJsonString($fixedRate->justification_snapshot),
                        rate_snapshot: [
                            'type' => 'fixed',
                            'rate_fixed' => (float)$fixedRate->rate_fixed,
                            'fixed_at' => $this->toIso8601String($fixedRate->fixed_at),
                            'is_locked' => false,
                            'sources_snapshot' => $fixedRate->sources_snapshot,
                        ]
                    );
                }
            }            // Попытка 3: Вычислить preview ставку из global_normohour_sources
            $previewRate = $this->calculatePreviewRate($profileId, $regionId);
            
            Log::debug('ProjectProfileRateResolver::resolveEffectiveRate - Preview rate calculated', [
                'profile_id' => $profileId,
                'region_id' => $regionId,
                'preview_rate' => $previewRate['rate'] ?? null,
                'justification' => $previewRate['justification'] ?? null,
            ]);
            
            if ($previewRate !== null) {
                // Применить модель формирования ставки (labor/contractor)
                $profile = PositionProfile::find($profileId);
                $rateModelParams = $profile ? $profile->getRateModelParams() : ['rate_model' => 'labor', 'rounding_mode' => 'none'];
                $rateModel = $rateModelParams['rate_model'] ?? 'labor';

                $calcResult = $this->rateModelCalculator->calculate(
                    $previewRate['rate'],
                    $rateModel,
                    $rateModelParams
                );
                $finalRate = $calcResult['final_rate'];
                $modelBreakdown = $calcResult['breakdown'];

                // Расширить justification snapshot информацией о модели
                $justificationData = $previewRate['justification'] ?? '{}';
                if (is_string($justificationData)) {
                    $justificationData = json_decode($justificationData, true) ?? [];
                }
                $justificationData['rate_model'] = $rateModel;
                $justificationData['base_rate'] = $previewRate['rate'];
                $justificationData['model_params'] = $rateModelParams;
                $justificationData['model_breakdown'] = $modelBreakdown;
                $justificationData['final_rate'] = $finalRate;

                return new RateDTO(
                    rate_per_hour: $finalRate,
                    rate_source: 'preview',
                    project_profile_rate_id: null,
                    sources_snapshot: json_encode($previewRate['sources'] ?? []),
                    justification_snapshot: json_encode($justificationData),
                    rate_snapshot: [
                        'type' => 'preview',
                        'rate' => $finalRate,
                        'base_rate' => $previewRate['rate'],
                        'rate_model' => $rateModel,
                        'model_breakdown' => $modelBreakdown,
                        'method' => $previewRate['method'] ?? 'median',
                        'calculated_at' => Carbon::now()->toIso8601String(),
                        'sources' => $previewRate['sources'] ?? [],
                    ]
                );
            }

            // Fallback: Нет ставки найдено
            Log::warning('No effective rate found', [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'region_id' => $regionId,
            ]);

            return new RateDTO(
                rate_per_hour: 0,
                rate_source: 'none',
                project_profile_rate_id: null,
                rate_snapshot: [
                    'type' => 'none',
                    'reason' => 'No rate found',
                    'checked_at' => Carbon::now()->toIso8601String(),
                ]
            );

        } catch (\Exception $e) {
            Log::error('Error resolving effective rate', [
                'project_id' => $projectId,
                'profile_id' => $profileId,
                'region_id' => $regionId,
                'error' => $e->getMessage(),
            ]);

            return new RateDTO(
                rate_per_hour: 0,
                rate_source: 'error',
                project_profile_rate_id: null,
                rate_snapshot: [
                    'type' => 'error',
                    'error' => $e->getMessage(),
                    'error_at' => Carbon::now()->toIso8601String(),
                ]
            );
        }
    }

    /**
     * Найти последнюю locked ставку
     */
    private function findLockedRate(int $projectId, int $profileId, ?int $regionId): ?ProjectProfileRate
    {
        return ProjectProfileRate::where('project_id', $projectId)
            ->where('profile_id', $profileId)
            ->where('is_locked', true)
            ->when($regionId, fn($q) => $q->where('region_id', $regionId))
            ->latest('locked_at')
            ->first();
    }

    /**
     * Найти последнюю fixed (не locked) ставку
     */
    private function findFixedRate(int $projectId, int $profileId, ?int $regionId): ?ProjectProfileRate
    {
        return ProjectProfileRate::where('project_id', $projectId)
            ->where('profile_id', $profileId)
            ->where('is_locked', false)
            ->when($regionId, fn($q) => $q->where('region_id', $regionId))
            ->latest('fixed_at')
            ->first();
    }

    /**
     * Рассчитать preview ставку из global_normohour_sources
     * 
     * @return array|null ['rate' => float, 'method' => string, 'sources' => array, 'justification' => string]
     */
    private function calculatePreviewRate(int $profileId, ?int $regionId): ?array
    {
        // Найти все активные источники для этого профиля
        $query = GlobalNormohourSource::where('position_profile_id', $profileId)
            ->where('is_active', true)
            ->whereNotNull('rate_per_hour');
        
        // Если есть регион, сначала пытаемся найти по региону
        if ($regionId) {
            $regionSources = (clone $query)
                ->where('region_id', $regionId)
                ->get();
                
            if ($regionSources->isNotEmpty()) {
                return $this->aggregateRatesFromSources($regionSources);
            }
        }
        
        // Fallback на источники без региона (глобальные)
        $globalSources = $query->where('region_id', null)->get();
        
        if ($globalSources->isNotEmpty()) {
            return $this->aggregateRatesFromSources($globalSources);
        }
        
        return null;
    }
    
    /**
     * Агрегировать ставки из набора источников
     * Использует медиану или среднее значение, с исключением выбросов
     */
    private function aggregateRatesFromSources($sources): array
    {
        $rates = $sources->pluck('rate_per_hour')
            ->map(fn($rate) => (float)$rate)
            ->sort()
            ->values()
            ->toArray();
        
        if (empty($rates)) {
            return null;
        }
        
        // DEBUG: логирование входных данных
        Log::debug('ProjectProfileRateResolver::aggregateRatesFromSources - Original rates', [
            'rates' => $rates,
            'count' => count($rates),
        ]);
        
        // Исключить выбросы
        $cleanedRates = $this->removeOutliers($rates);
        
        // DEBUG: логирование после очистки
        Log::debug('ProjectProfileRateResolver::aggregateRatesFromSources - After outlier removal', [
            'cleaned_rates' => $cleanedRates,
            'removed_count' => count($rates) - count($cleanedRates),
            'removed' => array_values(array_diff($rates, $cleanedRates)),
        ]);
        
        // Если после очистки осталась хоть одна ставка - используем её
        if (empty($cleanedRates)) {
            $cleanedRates = $rates;  // Fallback: используем все ставки
        }
        
        // Вычислить медиану из очищенных данных
        $median = $this->calculateMedian($cleanedRates);
        
        Log::debug('ProjectProfileRateResolver::aggregateRatesFromSources - Calculated median', [
            'median' => $median,
            'from_count' => count($cleanedRates),
        ]);
        // Подготовить snapshot ВСЕХ источников (не только очищенных) для информативности
        $sourcesSnapshot = $sources->map(fn($source) => [
            'id' => $source->id,
            'source' => $source->source,
            'rate_per_hour' => (float)$source->rate_per_hour,
            'region_id' => $source->region_id,
            'source_date' => $source->source_date?->toDateString(),
            'link' => $source->link,
        ])->toArray();
        
        // Определить были ли исключены выбросы
        $outlierCount = count($rates) - count($cleanedRates);
        $excludedRates = array_values(array_diff($rates, $cleanedRates));
        
        $justification = sprintf(
            'Медиана из %d источников: %s',
            count($cleanedRates),
            implode(', ', array_map(fn($r) => number_format($r, 2), $cleanedRates))
        );
        
        if ($outlierCount > 0) {
            $justification .= sprintf(
                ' (исключено %d выбросов: %s)',
                $outlierCount,
                implode(', ', array_map(fn($r) => number_format($r, 2), $excludedRates))
            );
        }
        
        // Расширенная структура justification_snapshot с полной информацией о расчёте
        $justificationSnapshot = [
            'method' => 'median',
            'all_rates' => $rates,
            'used_rates' => $cleanedRates,
            'excluded_rates' => $excludedRates,
            'median_before_filtering' => $this->calculateMedian($rates),
            'threshold' => 3.0,  // 3x от медианы для исключения выбросов
            'result_rate' => $median,
            'additional_note' => $justification,  // Свободный комментарий, не влияет на расчёт
        ];
        
        return [
            'rate' => $median,
            'method' => 'median',
            'sources' => $sourcesSnapshot,
            'justification' => json_encode($justificationSnapshot),
        ];
    }
    
    /**
     * Исключить выбросы из массива
     * Использует итеративный метод: удаляет самое экстремальное значение если оно в 3+ раза отличается от медианы остальных
     * 
     * Логика:
     * - Для нормальных значений типа [625, 1125, 1313] - не удаляет ничего (все в пределах ~2x)
     * - Для экстремального выброса типа [625, 1000, 6937.50] - удаляет 6937.50 (в 7x больше медианы остальных)
     */
    private function removeOutliers(array $rates): array
    {
        $count = count($rates);
        
        if ($count <= 2) {
            return $rates;
        }
        
        // Проверим самое экстремальное значение
        $min = min($rates);
        $max = max($rates);
        $median = $this->calculateMedian($rates);
        
        // Проверим максимальное значение - если в 3+ раза больше медианы, удалим его
        if ($max > $median * 3) {
            $filtered = array_values(array_filter($rates, fn($r) => $r != $max));
            if (count($filtered) >= 1) {
                // Рекурсивно проверим оставшиеся значения
                return $this->removeOutliers($filtered);
            }
        }
        
        // Проверим минимальное значение - если в 3+ раза меньше медианы, удалим его
        if ($min < $median / 3) {
            $filtered = array_values(array_filter($rates, fn($r) => $r != $min));
            if (count($filtered) >= 1) {
                // Рекурсивно проверим оставшиеся значения
                return $this->removeOutliers($filtered);
            }
        }
        
        return $rates;
    }
    
    /**
     * Вычислить медиану из массива чисел
     */
    private function calculateMedian(array $values): float
    {
        $count = count($values);
        
        if ($count === 0) {
            return 0;
        }
        
        if ($count === 1) {
            return $values[0];
        }
        
        // Переиндексировать массив если нужно
        $values = array_values($values);
        
        if ($count % 2 === 1) {
            // Нечётное количество - центральный элемент
            return $values[intval($count / 2)];
        }
        
        // Чётное количество - среднее двух центральных элементов
        $mid1 = $values[intval($count / 2) - 1];
        $mid2 = $values[intval($count / 2)];
        
        return ($mid1 + $mid2) / 2;
    }
}
