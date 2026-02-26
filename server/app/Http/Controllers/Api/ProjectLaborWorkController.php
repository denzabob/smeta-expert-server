<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Services\LaborWorkRateBinder;
use Illuminate\Http\Request;

class ProjectLaborWorkController extends Controller
{
    private LaborWorkRateBinder $rateBinder;

    public function __construct(LaborWorkRateBinder $rateBinder)
    {
        $this->rateBinder = $rateBinder;
    }

    /**
     * Get all labor works for a project
     */
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        
        return $project->laborWorks()->get();
    }

    /**
     * Create a new labor work
     */
    public function store(Project $project, Request $request)
    {
        $this->authorize('update', $project);

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::store - incoming request', [
            'project_id' => $project->id,
            'request_data' => $request->all(),
        ]);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'basis' => 'nullable|string|max:500',
            'hours' => 'required|numeric|min:0|max:999.99',
            'position_profile_id' => 'required|integer|exists:position_profiles,id',
            'note' => 'nullable|string|max:5000',
            'sort_order' => 'nullable|integer|min:0',
            'hours_source' => 'sometimes|in:manual,from_steps',
            'hours_manual' => 'sometimes|nullable|numeric|min:0|max:999.99',
        ]);

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::store - validated data', [
            'project_id' => $project->id,
            'validated_data' => $validated,
        ]);

        // Установить максимальный sort_order + 1 если не указан
        if (!isset($validated['sort_order'])) {
            $maxSort = $project->laborWorks()->max('sort_order') ?? 0;
            $validated['sort_order'] = $maxSort + 1;
        }

        $validated['project_id'] = $project->id;
        $work = ProjectLaborWork::create($validated);

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::store - work created', [
            'work_id' => $work->id,
            'position_profile_id' => $work->position_profile_id,
            'has_profile' => (bool)$work->position_profile_id,
        ]);

        // Автоматически привязать ставку к работе только если есть position_profile_id
        if ($work->position_profile_id) {
            $this->rateBinder->bindRate($work);
            $work->refresh();
        } else {
            \Illuminate\Support\Facades\Log::warning('ProjectLaborWorkController::store - no profile, rate binding skipped', [
                'work_id' => $work->id,
                'title' => $work->title,
            ]);
        }

        return response()->json($work, 201);
    }

    /**
     * Get a single labor work
     */
    public function show(Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('view', $project);

        // Verify that the work belongs to this project
        if ($laborWork->project_id !== $project->id) {
            return response()->json(['error' => 'Work not found'], 404);
        }

        return response()->json($laborWork);
    }

    /**
     * Update a labor work
     */
    public function update(Project $project, ProjectLaborWork $laborWork, Request $request)
    {
        $this->authorize('update', $project);

        // Verify that the work belongs to this project
        if ($laborWork->project_id !== $project->id) {
            return response()->json(['error' => 'Work not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'basis' => 'nullable|string|max:500',
            'hours' => 'required|numeric|min:0|max:999.99',
            'position_profile_id' => 'required|integer|exists:position_profiles,id',
            'note' => 'nullable|string|max:5000',
            'sort_order' => 'nullable|integer|min:0',
            'hours_source' => 'sometimes|in:manual,from_steps',
            'hours_manual' => 'sometimes|nullable|numeric|min:0|max:999.99',
        ]);

        $laborWork->update($validated);

        // Пересчитать ставку если изменились часы или профиль должности
        if (($request->filled('hours') || $request->filled('position_profile_id')) && $laborWork->position_profile_id) {
            $this->rateBinder->bindRate($laborWork);
            $laborWork->refresh();
        }

        return response()->json($laborWork);
    }

    /**
     * Delete a labor work
     */
    public function destroy(Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        // Verify that the work belongs to this project
        if ($laborWork->project_id !== $project->id) {
            return response()->json(['error' => 'Work not found'], 404);
        }

        $laborWork->delete();

        return response()->json(null, 204);
    }

    /**
     * Reorder labor works (PATCH)
     */
    public function reorder(Project $project, Request $request)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'required|integer|exists:project_labor_works,id',
        ]);

        // Проверить что все работы принадлежат проекту
        $workIds = $validated['order'];
        $projectWorkIds = $project->laborWorks()->pluck('id')->toArray();
        
        if (count(array_diff($workIds, $projectWorkIds)) > 0) {
            return response()->json(['error' => 'Invalid work IDs'], 400);
        }

        // Обновить sort_order для каждой работы
        foreach ($workIds as $index => $id) {
            ProjectLaborWork::where('id', $id)
                ->where('project_id', $project->id)
                ->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'Reordered successfully']);
    }
}
