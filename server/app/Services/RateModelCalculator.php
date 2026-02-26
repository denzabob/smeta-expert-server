<?php

namespace App\Services;

/**
 * Калькулятор ставки по модели формирования
 * 
 * Поддерживает две модели:
 * - LABOR: ставка = агрегированная ставка из источников (base_rate)
 * - CONTRACTOR: ставка = подрядная ставка с учётом страховых, загрузки и рентабельности
 * 
 * Формулы для contractor:
 *   contrib_rate = base_rate × (employer_contrib_pct / 100)
 *   loaded_labor_rate = base_rate + contrib_rate
 *   utilization_k = base_hours_month / billable_hours_month
 *   cost_rate = loaded_labor_rate × utilization_k
 *   profit_amount = cost_rate × (profit_pct / 100)
 *   contractor_rate = cost_rate + profit_amount
 *   final_rate = applyRounding(contractor_rate, rounding_mode)
 */
class RateModelCalculator
{
    /**
     * Рассчитать итоговую ставку по модели
     *
     * @param float $baseRate Агрегированная ставка из источников (руб/ч)
     * @param string $rateModel 'labor' | 'contractor'
     * @param array $params Параметры модели:
     *   - employer_contrib_pct (float): Страховые начисления, % (default 30)
     *   - base_hours_month (int): Рабочих часов в месяц (default 160)
     *   - billable_hours_month (int): Оплачиваемых часов в месяц (default 120)
     *   - profit_pct (float): Рентабельность, % (default 15)
     *   - rounding_mode (string): Округление: none|int|10|100 (default 'none')
     * @return array ['final_rate' => float, 'breakdown' => array]
     */
    public function calculate(float $baseRate, string $rateModel, array $params = []): array
    {
        $rateModel = $rateModel ?: 'labor';
        $roundingMode = $params['rounding_mode'] ?? 'none';

        if ($rateModel === 'contractor') {
            return $this->calculateContractor($baseRate, $params, $roundingMode);
        }

        return $this->calculateLabor($baseRate, $roundingMode);
    }

    /**
     * Расчёт по модели LABOR (текущая модель)
     */
    private function calculateLabor(float $baseRate, string $roundingMode): array
    {
        $finalRate = $this->applyRounding($baseRate, $roundingMode);

        return [
            'final_rate' => $finalRate,
            'breakdown' => [
                'rate_model' => 'labor',
                'base_rate' => round($baseRate, 2),
                'rounding_mode' => $roundingMode,
                'final_rate' => $finalRate,
            ],
        ];
    }

    /**
     * Расчёт по модели CONTRACTOR (подрядная модель)
     */
    private function calculateContractor(float $baseRate, array $params, string $roundingMode): array
    {
        $employerContribPct = (float) ($params['employer_contrib_pct'] ?? 30.0);
        $baseHoursMonth = (int) ($params['base_hours_month'] ?? 160);
        $billableHoursMonth = (int) ($params['billable_hours_month'] ?? 120);
        $profitPct = (float) ($params['profit_pct'] ?? 15.0);

        // Защита от деления на ноль
        if ($billableHoursMonth <= 0) {
            $billableHoursMonth = $baseHoursMonth;
        }

        // 1. Страховые начисления
        $contribRate = $baseRate * ($employerContribPct / 100);
        $loadedLaborRate = $baseRate + $contribRate;

        // 2. Коэффициент загрузки
        $utilizationK = $baseHoursMonth / $billableHoursMonth;
        $costRate = $loadedLaborRate * $utilizationK;

        // 3. Рентабельность
        $profitAmount = $costRate * ($profitPct / 100);
        $contractorRate = $costRate + $profitAmount;

        // 4. Округление
        $finalRate = $this->applyRounding($contractorRate, $roundingMode);

        return [
            'final_rate' => $finalRate,
            'breakdown' => [
                'rate_model' => 'contractor',
                'base_rate' => round($baseRate, 2),
                'employer_contrib_pct' => $employerContribPct,
                'contrib_rate' => round($contribRate, 2),
                'loaded_labor_rate' => round($loadedLaborRate, 2),
                'base_hours_month' => $baseHoursMonth,
                'billable_hours_month' => $billableHoursMonth,
                'utilization_k' => round($utilizationK, 4),
                'cost_rate' => round($costRate, 2),
                'profit_pct' => $profitPct,
                'profit_amount' => round($profitAmount, 2),
                'contractor_rate' => round($contractorRate, 2),
                'rounding_mode' => $roundingMode,
                'final_rate' => $finalRate,
            ],
        ];
    }

    /**
     * Применить округление к ставке
     *
     * @param float $rate Исходная ставка
     * @param string $mode Режим: none | int | 10 | 100
     * @return float Округлённая ставка
     */
    private function applyRounding(float $rate, string $mode): float
    {
        return match ($mode) {
            'int' => round($rate),
            '10' => round($rate / 10) * 10,
            '100' => round($rate / 100) * 100,
            default => round($rate, 2), // 'none' — до копеек
        };
    }
}
