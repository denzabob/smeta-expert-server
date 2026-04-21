<?php

namespace App\Services;

use App\Models\LaborEvidenceSource;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;

class LaborCostCalculationService
{
    public function __construct(
        private readonly UserLaborSettingsResolver $settingsResolver,
    ) {
    }

    public function calculate(Project $project, User $user): array
    {
        $settings = $this->settingsResolver->resolve($user);
        $calculatedAt = Carbon::now()->toIso8601String();

        $sources = $project->laborEvidenceSources()
            ->with(['provider', 'region', 'laborProfile'])
            ->where('labor_evidence_sources.is_active', true)
            ->orderBy('labor_evidence_sources.id')
            ->get();

        $skippedSources = [];
        $profileBuckets = [];
        $warnings = [];

        /** @var LaborEvidenceSource $source */
        foreach ($sources as $source) {
            if ($source->labor_profile_id === null) {
                $skippedSources[] = $this->buildSkippedSourcePayload($source, 'missing_labor_profile_id');
                continue;
            }

            $profileBuckets[$source->labor_profile_id] ??= [
                'labor_profile_id' => $source->labor_profile_id,
                'labor_profile_name' => $source->laborProfile?->title,
                'normalized_rates' => [],
                'used_sources' => [],
                'skipped_sources' => [],
            ];

            $normalization = $this->normalizeSource(
                $source,
                $settings['rounding_scale'],
                $settings['salary_range_strategy']
            );

            if (!$normalization['used']) {
                $skippedPayload = $this->buildSkippedSourcePayload($source, $normalization['reason']);
                $skippedSources[] = $skippedPayload;
                $profileBuckets[$source->labor_profile_id]['skipped_sources'][] = $skippedPayload;
                continue;
            }

            $profileBuckets[$source->labor_profile_id]['normalized_rates'][] = $normalization['hourly_rate'];
            $profileBuckets[$source->labor_profile_id]['used_sources'][] = [
                'source_id' => $source->id,
                'title' => $source->vacancy_title ?: $source->source_title,
                'provider' => $source->provider?->title,
                'employer_name' => $source->employer_name,
                'source_url' => $source->source_url,
                'source_date' => $source->source_date?->toDateString(),
                'normalization_method' => $normalization['method'],
                'selected_salary_amount' => $normalization['selected_salary_amount'] ?? null,
                'salary_range_strategy' => $normalization['salary_range_strategy'] ?? null,
                'hourly_rate' => $normalization['hourly_rate'],
            ];
        }

        ksort($profileBuckets);

        $profiles = [];
        $totalUsedSources = 0;

        foreach ($profileBuckets as $bucket) {
            $profileWarnings = [];
            $usedCount = count($bucket['normalized_rates']);
            $skippedCount = count($bucket['skipped_sources']);

            if ($usedCount === 0) {
                $profileWarnings[] = 'no_valid_labor_sources_for_profile';
            }

            $aggregation = $this->aggregateRates(
                $bucket['normalized_rates'],
                $settings['aggregation_strategy'],
                $settings['rounding_scale']
            );
            $baseRate = $aggregation['base_rate'];
            $model = $this->buildEconomicModel($baseRate, $settings);

            if ($usedCount === 0) {
                $profileWarnings[] = 'profile_calculation_not_performed_due_to_no_valid_sources';
            }

            $profiles[] = [
                'labor_profile_id' => $bucket['labor_profile_id'],
                'labor_profile_name' => $bucket['labor_profile_name'],
                'sources' => [
                    'used_count' => $usedCount,
                    'skipped_count' => $skippedCount,
                    'used_sources' => $bucket['used_sources'],
                    'skipped_sources' => $bucket['skipped_sources'],
                ],
                'normalized_rates' => $bucket['normalized_rates'],
                'aggregation' => $aggregation,
                'model' => $model,
                'settings' => [
                    'aggregation_strategy' => $settings['aggregation_strategy'],
                    'salary_range_strategy' => $settings['salary_range_strategy'],
                    'employer_insurance_rate' => $settings['insurance_rate'],
                    'load_factor_calendar_hours' => $settings['calendar_hours'],
                    'load_factor_productive_hours' => $settings['productive_hours'],
                    'planned_profitability_rate' => $settings['profitability_rate'],
                    'rounding_scale' => $settings['rounding_scale'],
                ],
                'calculation_breakdown' => $this->buildCalculationBreakdown(
                    $aggregation['base_rate'],
                    $aggregation['method'],
                    $model,
                    $settings
                ),
                'warnings' => $profileWarnings,
            ];

            $totalUsedSources += $usedCount;
        }

        if (empty($profiles)) {
            $warnings[] = 'no_valid_labor_sources';
        }

        if (count($profiles) > 1) {
            $warnings[] = 'multiple_profiles_present_project_level_rate_deprecated';
        }

        if (collect($skippedSources)->contains(fn (array $item) => $item['reason'] === 'missing_labor_profile_id')) {
            $warnings[] = 'unassigned_sources_skipped_due_to_missing_labor_profile';
        }

        $deprecatedFallback = $this->buildDeprecatedProjectLevelFallback($profiles);

        return [
            'project_id' => $project->id,
            'calculated_at' => $calculatedAt,
            'region' => [
                'id' => $project->region_id,
                'name' => $project->region?->name ?? $project->region?->region_name,
            ],
            'profiles' => $profiles,
            'summary' => [
                'profiles_count' => count($profiles),
                'total_used_sources' => $totalUsedSources,
                'total_skipped_sources' => count($skippedSources),
            ],
            'mapping' => [
                'mode' => 'direct',
                'works_mapping_supported' => true,
                'note' => 'Project labor works use labor_profile_id directly',
            ],
            'deprecated_project_level_rate' => true,
            'settings' => [
                'aggregation_strategy' => $settings['aggregation_strategy'],
                'salary_range_strategy' => $settings['salary_range_strategy'],
                'employer_insurance_rate' => $settings['insurance_rate'],
                'load_factor_calendar_hours' => $settings['calendar_hours'],
                'load_factor_productive_hours' => $settings['productive_hours'],
                'planned_profitability_rate' => $settings['profitability_rate'],
                'rounding_scale' => $settings['rounding_scale'],
            ],
            'sources' => $deprecatedFallback['sources'],
            'normalized_rates' => $deprecatedFallback['normalized_rates'],
            'aggregation' => $deprecatedFallback['aggregation'],
            'model' => $deprecatedFallback['model'],
            'skipped_sources' => $skippedSources,
            'warnings' => $warnings,
        ];
    }

    private function buildSkippedSourcePayload(LaborEvidenceSource $source, string $reason): array
    {
        return [
            'source_id' => $source->id,
            'reason' => $reason,
            'source_title' => $source->vacancy_title ?: $source->source_title,
            'provider' => $source->provider?->title,
            'labor_profile_id' => $source->labor_profile_id,
            'labor_profile_name' => $source->laborProfile?->title,
        ];
    }

    private function buildDeprecatedProjectLevelFallback(array $profiles): array
    {
        if (count($profiles) !== 1) {
            return [
                'sources' => null,
                'normalized_rates' => [],
                'aggregation' => null,
                'model' => null,
            ];
        }

        $profile = $profiles[0];

        return [
            'sources' => $profile['sources'],
            'normalized_rates' => $profile['normalized_rates'],
            'aggregation' => $profile['aggregation'],
            'model' => $profile['model'],
        ];
    }

    private function normalizeSource(LaborEvidenceSource $source, int $roundingScale, string $salaryRangeStrategy): array
    {
        if (!$source->evidence_record_id) {
            return [
                'used' => false,
                'reason' => 'missing_evidence_record',
            ];
        }

        if ($source->derived_hourly_rate !== null) {
            return [
                'used' => true,
                'method' => 'derived_hourly_rate',
                'selected_salary_amount' => null,
                'salary_range_strategy' => null,
                'hourly_rate' => round((float) $source->derived_hourly_rate, $roundingScale),
            ];
        }

        $baseAmount = $this->extractSalaryAmount($source, $salaryRangeStrategy);
        if ($baseAmount === null) {
            return [
                'used' => false,
                'reason' => 'missing_salary_data',
            ];
        }

        $period = $source->salary_period;
        if ($period === 'hour') {
            return [
                'used' => true,
                'method' => 'hourly_salary',
                'selected_salary_amount' => round($baseAmount, $roundingScale),
                'salary_range_strategy' => $this->hasSalaryRange($source) ? $salaryRangeStrategy : null,
                'hourly_rate' => round($baseAmount, $roundingScale),
            ];
        }

        if ($period === 'month') {
            $hoursPerMonth = max(1, (int) ($source->hours_per_month ?: 0));

            return [
                'used' => true,
                'method' => 'monthly_salary',
                'selected_salary_amount' => round($baseAmount, $roundingScale),
                'salary_range_strategy' => $this->hasSalaryRange($source) ? $salaryRangeStrategy : null,
                'hourly_rate' => round($baseAmount / $hoursPerMonth, $roundingScale),
            ];
        }

        return [
            'used' => false,
            'reason' => $period ? 'unsupported_salary_period' : 'missing_salary_period',
        ];
    }

    private function extractSalaryAmount(LaborEvidenceSource $source, string $salaryRangeStrategy): ?float
    {
        if ($source->salary_value !== null) {
            return (float) $source->salary_value;
        }

        $min = $source->salary_value_min !== null ? (float) $source->salary_value_min : null;
        $max = $source->salary_value_max !== null ? (float) $source->salary_value_max : null;

        if ($min !== null && $max !== null) {
            return match ($salaryRangeStrategy) {
                'min' => $min,
                'max' => $max,
                default => ($min + $max) / 2,
            };
        }

        if ($min !== null) {
            return $min;
        }

        if ($max !== null) {
            return $max;
        }

        return null;
    }

    private function hasSalaryRange(LaborEvidenceSource $source): bool
    {
        return $source->salary_value === null
            && $source->salary_value_min !== null
            && $source->salary_value_max !== null;
    }

    private function aggregateRates(array $rates, string $strategy, int $roundingScale): array
    {
        if (empty($rates)) {
            return [
                'strategy' => $strategy,
                'method' => 'none',
                'base_rate' => null,
            ];
        }

        sort($rates);
        $count = count($rates);
        $method = $strategy;

        if ($strategy === 'auto') {
            if ($count === 1) {
                $method = 'single';
            } elseif ($count === 2) {
                $method = 'mean';
            } else {
                $method = 'median';
            }
        }

        $baseRate = match ($method) {
            'single' => (float) $rates[0],
            'mean' => array_sum($rates) / $count,
            'median' => $this->calculateMedian($rates),
            'min' => min($rates),
            'max' => max($rates),
            default => array_sum($rates) / $count,
        };

        return [
            'strategy' => $strategy,
            'method' => $method,
            'base_rate' => round($baseRate, $roundingScale),
        ];
    }

    private function calculateMedian(array $rates): float
    {
        $count = count($rates);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($rates[$middle - 1] + $rates[$middle]) / 2;
        }

        return $rates[$middle];
    }

    private function buildEconomicModel(?float $baseRate, array $settings): array
    {
        if ($baseRate === null) {
            return [
                'insurance_rate' => $settings['insurance_rate'],
                'insurance_amount' => null,
                'loaded_rate' => null,
                'load_factor' => null,
                'cost_rate' => null,
                'profitability_rate' => $settings['profitability_rate'],
                'profit_amount' => null,
                'final_rate' => null,
                'rounding_scale' => $settings['rounding_scale'],
            ];
        }

        $roundingScale = $settings['rounding_scale'];
        $insuranceAmount = $baseRate * $settings['insurance_rate'];
        $loadedRate = $baseRate * (1 + $settings['insurance_rate']);
        $loadFactor = $settings['calendar_hours'] / max(1, $settings['productive_hours']);
        $costRate = $loadedRate * $loadFactor;
        $profitAmount = $costRate * $settings['profitability_rate'];
        $finalRate = $costRate + $profitAmount;

        return [
            'insurance_rate' => $settings['insurance_rate'],
            'insurance_amount' => round($insuranceAmount, $roundingScale),
            'loaded_rate' => round($loadedRate, $roundingScale),
            'load_factor' => round($loadFactor, 4),
            'calendar_hours' => $settings['calendar_hours'],
            'productive_hours' => $settings['productive_hours'],
            'cost_rate' => round($costRate, $roundingScale),
            'profitability_rate' => $settings['profitability_rate'],
            'profit_amount' => round($profitAmount, $roundingScale),
            'final_rate' => round($finalRate, $roundingScale),
            'rounding_scale' => $roundingScale,
        ];
    }

    private function buildCalculationBreakdown(?float $baseRate, string $aggregationMethod, array $model, array $settings): array
    {
        return [
            'base_rate' => $baseRate,
            'insurance_rate' => $model['insurance_rate'],
            'insurance_amount' => $model['insurance_amount'],
            'loaded_rate' => $model['loaded_rate'],
            'load_factor' => $model['load_factor'],
            'calendar_hours' => $model['calendar_hours'] ?? $settings['calendar_hours'],
            'productive_hours' => $model['productive_hours'] ?? $settings['productive_hours'],
            'cost_rate' => $model['cost_rate'],
            'profitability_rate' => $model['profitability_rate'],
            'profit_amount' => $model['profit_amount'],
            'final_rate' => $model['final_rate'],
            'salary_range_strategy' => $settings['salary_range_strategy'],
            'aggregation_method' => $aggregationMethod,
            'rounding_scale' => $model['rounding_scale'],
        ];
    }
}
