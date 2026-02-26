<?php

namespace App\Observers;

use App\Models\ProjectLaborWorkStep;
use App\Services\LaborWorkHoursCalculator;
use Illuminate\Support\Facades\Log;

class ProjectLaborWorkStepObserver
{
    private LaborWorkHoursCalculator $hoursCalculator;

    public function __construct(LaborWorkHoursCalculator $hoursCalculator)
    {
        $this->hoursCalculator = $hoursCalculator;
    }

    /**
     * При создании подоперации - пересчитать часы родителя
     */
    public function created(ProjectLaborWorkStep $step): void
    {
        try {
            Log::info('Observer: step created event START', [
                'step_id' => $step->id,
                'step_hours' => $step->hours,
                'labor_work_id' => $step->project_labor_work_id,
            ]);
            
            $work = $step->laborWork;
            if ($work) {
                Log::info('Observer: recalculating hours for work', [
                    'work_id' => $work->id,
                    'current_hours' => $work->hours,
                    'hours_source' => $work->hours_source,
                ]);
                
                $this->hoursCalculator->recalculateHours($work);
                
                Log::info('Observer: hours recalculated successfully', [
                    'work_id' => $work->id,
                    'new_hours' => $work->fresh()->hours,
                ]);
            } else {
                Log::warning('Observer: laborWork relation is null', ['step_id' => $step->id]);
            }
            
            Log::info('Observer: step created event END', ['step_id' => $step->id]);
        } catch (\Exception $e) {
            Log::error('Observer error in created event', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Do not rethrow to avoid failing the API response after the step is created
        }
    }

    /**
     * При обновлении подоперации - пересчитать часы родителя
     */
    public function updated(ProjectLaborWorkStep $step): void
    {
        try {
            Log::debug('Observer: step updated event', ['step_id' => $step->id]);
            $work = $step->laborWork;
            if ($work) {
                Log::debug('Observer: recalculating hours for work', ['work_id' => $work->id]);
                $this->hoursCalculator->recalculateHours($work);
                Log::debug('Observer: hours recalculated successfully', ['work_id' => $work->id]);
            }
        } catch (\Exception $e) {
            Log::error('Observer error in updated event', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Do not rethrow to avoid failing the API response after the step is updated
        }
    }

    /**
     * При удалении подоперации - пересчитать часы родителя
     */
    public function deleted(ProjectLaborWorkStep $step): void
    {
        try {
            Log::debug('Observer: step deleted event', ['step_id' => $step->id]);
            // Получить работу до удаления связи
            $workId = $step->project_labor_work_id;
            $work = \App\Models\ProjectLaborWork::find($workId);
            
            if ($work) {
                Log::debug('Observer: recalculating hours for work', ['work_id' => $work->id]);
                $this->hoursCalculator->recalculateHours($work);
                Log::debug('Observer: hours recalculated successfully', ['work_id' => $work->id]);
            }
        } catch (\Exception $e) {
            Log::error('Observer error in deleted event', [
                'step_id' => $step->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Do not rethrow to avoid failing the API response after the step is deleted
        }
    }
}
