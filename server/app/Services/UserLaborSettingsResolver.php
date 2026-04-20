<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSettings;

class UserLaborSettingsResolver
{
    public const ALLOWED_AGGREGATION_STRATEGIES = [
        'auto',
        'median',
        'mean',
        'min',
        'max',
    ];

    /**
     * Вернуть user_settings пользователя, создав запись с дефолтами при необходимости.
     */
    public function getOrCreateSettings(User $user): UserSettings
    {
        return $user->settings()->firstOrCreate(
            ['user_id' => $user->id],
            UserSettings::defaultAttributes(),
        );
    }

    /**
     * Вернуть нормализованный набор настроек труда для бизнес-логики.
     *
     * @return array{
     *     insurance_rate: float,
     *     calendar_hours: int,
     *     productive_hours: int,
     *     profitability_rate: float,
     *     aggregation_strategy: string,
     *     rounding_scale: int
     * }
     */
    public function resolve(User $user): array
    {
        $settings = $this->getOrCreateSettings($user);

        $strategy = (string) ($settings->labor_aggregation_strategy ?: UserSettings::DEFAULT_LABOR_AGGREGATION_STRATEGY);
        if (!in_array($strategy, self::ALLOWED_AGGREGATION_STRATEGIES, true)) {
            $strategy = UserSettings::DEFAULT_LABOR_AGGREGATION_STRATEGY;
        }

        $calendarHours = (int) ($settings->labor_load_factor_calendar_hours ?: UserSettings::DEFAULT_LABOR_LOAD_FACTOR_CALENDAR_HOURS);
        $productiveHours = (int) ($settings->labor_load_factor_productive_hours ?: UserSettings::DEFAULT_LABOR_LOAD_FACTOR_PRODUCTIVE_HOURS);
        $roundingScale = (int) ($settings->labor_rate_rounding_scale ?? UserSettings::DEFAULT_LABOR_RATE_ROUNDING_SCALE);

        return [
            'insurance_rate' => (float) ($settings->labor_employer_insurance_rate ?? UserSettings::DEFAULT_LABOR_EMPLOYER_INSURANCE_RATE),
            'calendar_hours' => max(1, $calendarHours),
            'productive_hours' => max(1, $productiveHours),
            'profitability_rate' => (float) ($settings->labor_planned_profitability_rate ?? UserSettings::DEFAULT_LABOR_PLANNED_PROFITABILITY_RATE),
            'aggregation_strategy' => $strategy,
            'rounding_scale' => max(0, $roundingScale),
        ];
    }
}
