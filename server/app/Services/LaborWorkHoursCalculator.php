<?php

namespace App\Services;

use App\Models\ProjectLaborWork;

class LaborWorkHoursCalculator
{
    /**
     * Пересчитать часы работы на основе её состояния
     * 
     * Логика:
     * - Если есть подоперации: hours = SUM(steps.hours), hours_source = 'from_steps'
     * - Если нет подопераций: использовать часы как есть, hours_source = 'manual'
     */
    public function recalculateHours(ProjectLaborWork $work): void
    {
        \Log::info('LaborWorkHoursCalculator: recalculateHours START', [
            'work_id' => $work->id,
            'current_hours' => $work->hours,
            'hours_source' => $work->hours_source,
        ]);
        
        $stepsCount = $work->steps()->count();
        $stepsTotal = (float)($work->steps()->sum('hours') ?? 0);

        \Log::info('LaborWorkHoursCalculator: steps data', [
            'steps_count' => $stepsCount,
            'steps_total' => $stepsTotal,
        ]);

        if ($stepsCount > 0) {
            // Есть подоперации - считаем по ним
            $work->hours_source = 'from_steps';
            $work->hours = $stepsTotal;
            $work->hours_manual = null;
            \Log::info('LaborWorkHoursCalculator: mode from_steps', [
                'new_hours' => $stepsTotal,
            ]);
        } else {
            // Нет подопераций - используем ручное значение
            if (!$work->hours_source || $work->hours_source === 'from_steps') {
                // Если был режим "from_steps", переключаемся на "manual"
                $work->hours_source = 'manual';
                // Если hours_manual пуст, используем текущее значение hours
                if (!$work->hours_manual && $work->hours) {
                    $work->hours_manual = $work->hours;
                }
            }
            \Log::info('LaborWorkHoursCalculator: mode manual');
        }

        \Log::info('LaborWorkHoursCalculator: saving work', [
            'hours' => $work->hours,
            'hours_source' => $work->hours_source,
        ]);
        
        // Использовать saveQuietly для предотвращения бесконечной рекурсии через Observer
        $work->saveQuietly();
        
        \Log::info('LaborWorkHoursCalculator: work saved');

        // Пересчитать стоимость если есть rate_per_hour
        if ($work->rate_per_hour) {
            \Log::info('LaborWorkHoursCalculator: recalculating cost');
            $this->recalculateCost($work);
        }
        
        \Log::info('LaborWorkHoursCalculator: recalculateHours END');
    }

    /**
     * Пересчитать стоимость работы
     * 
     * cost_total = hours * rate_per_hour
     */
    public function recalculateCost(ProjectLaborWork $work): void
    {
        $effectiveHours = (float)$work->getEffectiveHours();
        $ratePerHour = (float)($work->rate_per_hour ?? 0);
        
        if ($ratePerHour > 0) {
            $work->cost_total = round($effectiveHours * $ratePerHour, 2);
            // Использовать saveQuietly для предотвращения бесконечной рекурсии через Observer
            $work->saveQuietly();
        }
    }

    /**
     * Автоматический выбор режима при создании работы
     * 
     * Если при создании указаны параметры для подопераций,
     * переключиться в режим from_steps
     */
    public function initializeHours(ProjectLaborWork $work): void
    {
        // Если явно не указан режим, выбираем автоматически
        if (!$work->hours_source || $work->hours_source === 'manual') {
            // На момент создания работы подопераций нет
            // поэтому режим всегда 'manual'
            $work->hours_source = 'manual';
            
            // Если не установлен hours_manual, используем hours
            if (!$work->hours_manual) {
                $work->hours_manual = $work->hours;
            }
        }
    }

    /**
     * Синхронизировать часы при обновлении основного поля
     */
    public function syncHours(ProjectLaborWork $work, ?float $newHours = null): void
    {
        if ($newHours !== null) {
            $work->hours = $newHours;
        }

        // Синхронизировать hours_manual если в режиме manual
        if ($work->isManualHours() && !$work->hours_manual) {
            $work->hours_manual = $work->hours;
        }

        // Использовать saveQuietly для предотвращения триггера Observer
        $work->saveQuietly();

        // Пересчитать стоимость если необходимо
        if ($work->rate_per_hour) {
            $this->recalculateCost($work);
        }
    }

    /**
     * Получить информацию о статусе расчета
     */
    public function getStatus(ProjectLaborWork $work): array
    {
        $stepsTotal = $work->steps()->sum('hours') ?? 0;
        $stepsCount = $work->steps()->count();
        $effectiveHours = $work->getEffectiveHours();

        return [
            'hours_source' => $work->hours_source,
            'is_manual' => $work->isManualHours(),
            'is_from_steps' => $work->isFromSteps(),
            'hours' => $work->hours,
            'hours_manual' => $work->hours_manual,
            'effective_hours' => $effectiveHours,
            'steps_count' => $stepsCount,
            'steps_total' => $stepsTotal,
            'rate_per_hour' => $work->rate_per_hour,
            'cost_total' => $work->cost_total,
            'calculated_cost' => $effectiveHours * ($work->rate_per_hour ?? 0),
        ];
    }
}
