<?php

namespace App\Observers;

use App\Models\ProjectLaborWork;
use App\Services\LaborWorkHoursCalculator;

class ProjectLaborWorkObserver
{
    private LaborWorkHoursCalculator $hoursCalculator;

    public function __construct(LaborWorkHoursCalculator $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    /**
     * При создании работы - инициализировать режим часов
     */
    public function created(ProjectLaborWork $work): void
    {
        // Убедиться, что hours_source всегда имеет значение
        if (!$work->hours_source) {
            $work->hours_source = 'manual';
            if (!$work->hours_manual) {
                $work->hours_manual = $work->hours;
            }
            // Использовать saveQuietly для предотвращения повторного вызова created
            $work->saveQuietly();
        } else {
            $this->hoursCalculator->initializeHours($work);
        }
    }

    /**
     * При сохранении работы - синхронизировать часы
     */
    public function updating(ProjectLaborWork $work): void
    {
        // Если hours_source не установлен - установить 'manual'
        if (!$work->hours_source) {
            $work->hours_source = 'manual';
        }
        
        // Если изменился hours в ручном режиме - синхронизировать hours_manual
        if ($work->isDirty('hours') && $work->isManualHours()) {
            $work->hours_manual = $work->hours;
        }
    }

    /**
     * После обновления работы - пересчитать стоимость если нужно
     */
    public function updated(ProjectLaborWork $work): void
    {
        // Если изменилась ставка или часы - пересчитать стоимость
        if ($work->isDirty('hours') || $work->isDirty('rate_per_hour')) {
            if ($work->rate_per_hour) {
                $this->hoursCalculator->recalculateCost($work);
            }
        }
    }
}
