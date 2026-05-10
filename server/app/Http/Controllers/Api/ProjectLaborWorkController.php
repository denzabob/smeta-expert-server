<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaborProfile;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Services\ProjectLaborWorkRateApplierService;
use Illuminate\Http\Request;

class ProjectLaborWorkController extends Controller
{
    public function __construct(
        private readonly ProjectLaborWorkRateApplierService $rateApplier,
    ) {
    }

    /**
     * Get all labor works for a project
     */
    public function index(Project $project)
    {
        $this->authorize('view', $project);

        return $project->laborWorks()
            ->with('laborProfile:id,title')
            ->withCount('steps')
            ->get()
            ->map(fn (ProjectLaborWork $work) => $this->serializeWork($work));
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
            'labor_profile_id' => 'required|integer|exists:labor_profiles,id',
            'note' => 'nullable|string|max:5000',
            'sort_order' => 'nullable|integer|min:0',
            'hours_source' => 'sometimes|in:manual,from_steps',
            'hours_manual' => 'sometimes|nullable|numeric|min:0|max:999.99',
        ]);

        $laborProfile = $this->findOwnedLaborProfileOrAbort($project, (int) $validated['labor_profile_id']);

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::store - validated data', [
            'project_id' => $project->id,
            'validated_data' => $validated,
        ]);

        // Установить максимальный sort_order + 1 если не указан
        if (!isset($validated['sort_order'])) {
            $maxSort = $project->laborWorks()->max('sort_order') ?? 0;
            $validated['sort_order'] = $maxSort + 1;
        }

        $validated['position_profile_id'] = null;
        $validated['project_id'] = $project->id;
        $work = ProjectLaborWork::create($validated);
        $this->rateApplier->apply($project);
        $work->refresh();
        $work->load('laborProfile:id,title');
        $work->loadCount('steps');

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::store - work created', [
            'work_id' => $work->id,
            'labor_profile_id' => $work->labor_profile_id,
            'labor_profile_title' => $laborProfile->title,
        ]);

        return response()->json($this->serializeWork($work), 201);
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

        $laborWork->load('laborProfile:id,title');
        $laborWork->loadCount('steps');

        return response()->json($this->serializeWork($laborWork));
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
            'labor_profile_id' => 'required|integer|exists:labor_profiles,id',
            'note' => 'nullable|string|max:5000',
            'sort_order' => 'nullable|integer|min:0',
            'hours_source' => 'sometimes|in:manual,from_steps',
            'hours_manual' => 'sometimes|nullable|numeric|min:0|max:999.99',
        ]);

        $laborProfile = $this->findOwnedLaborProfileOrAbort($project, (int) $validated['labor_profile_id']);
        $validated['position_profile_id'] = null;
        $laborWork->update($validated);
        $this->rateApplier->apply($project);
        $laborWork->refresh();
        $laborWork->load('laborProfile:id,title');
        $laborWork->loadCount('steps');

        \Illuminate\Support\Facades\Log::info('ProjectLaborWorkController::update - work updated', [
            'work_id' => $laborWork->id,
            'labor_profile_id' => $laborWork->labor_profile_id,
            'labor_profile_title' => $laborProfile->title,
        ]);

        return response()->json($this->serializeWork($laborWork));
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

    private function findOwnedLaborProfileOrAbort(Project $project, int $laborProfileId): LaborProfile
    {
        $laborProfile = LaborProfile::query()
            ->whereKey($laborProfileId)
            ->where('user_id', $project->user_id)
            ->whereNull('deleted_at')
            ->first();

        if ($laborProfile) {
            return $laborProfile;
        }

        abort(403, 'Selected labor profile does not belong to current project owner.');
    }

    private function serializeWork(ProjectLaborWork $work): array
    {
        $payload = $work->toArray();
        $payload['labor_profile_name'] = $work->laborProfile?->title;

        return $payload;
    }
}
