<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Services\LaborWorkHoursCalculator;
use Illuminate\Http\Request;

class LaborWorkHoursController extends Controller
{
    private LaborWorkHoursCalculator $calculator;

    public function __construct(LaborWorkHoursCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * Переключить источник часов на "вручную"
     * POST /api/projects/{projectId}/labor-works/{laborWorkId}/hours/set-manual
     */
    public function setManual(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'hours' => 'required|numeric|min:0|max:999.99',
        ]);

        $laborWork->setManualHours((float)$validated['hours']);

        return response()->json([
            'message' => 'Hours set to manual',
            'hours_source' => $laborWork->hours_source,
            'hours' => $laborWork->hours,
            'hours_manual' => $laborWork->hours_manual,
        ]);
    }

    /**
     * Переключить источник часов на "из подопераций"
     * POST /api/projects/{projectId}/labor-works/{laborWorkId}/hours/set-from-steps
     */
    public function setFromSteps(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $laborWork->setHoursFromSteps();

        return response()->json([
            'message' => 'Hours set from steps',
            'hours_source' => $laborWork->hours_source,
            'hours' => $laborWork->hours,
            'steps_total' => $laborWork->steps()->sum('hours'),
        ]);
    }

    /**
     * Получить информацию о часах
     * GET /api/projects/{projectId}/labor-works/{laborWorkId}/hours/info
     */
    public function getInfo(Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('view', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $stepsTotal = $laborWork->steps()->sum('hours') ?? 0;

        return response()->json([
            'id' => $laborWork->id,
            'hours_source' => $laborWork->hours_source,
            'is_manual' => $laborWork->isManualHours(),
            'is_from_steps' => $laborWork->isFromSteps(),
            'hours' => $laborWork->hours,
            'hours_manual' => $laborWork->hours_manual,
            'effective_hours' => $laborWork->getEffectiveHours(),
            'steps_count' => $laborWork->steps()->count(),
            'steps_total' => $stepsTotal,
            'steps' => $laborWork->steps()->get(['id', 'title', 'hours', 'sort_order']),
        ]);
    }

    /**
     * Пересчитать часы работы на основе её состояния
     * POST /api/projects/{projectId}/labor-works/{laborWorkId}/hours/recalculate
     */
    public function recalculate(Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $this->calculator->recalculateHours($laborWork);
        $laborWork->refresh();

        $status = $this->calculator->getStatus($laborWork);

        return response()->json([
            'message' => 'Hours recalculated',
            'status' => $status,
        ]);
    }
}
