<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectLaborWorkStepController extends Controller
{
    public function index(Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('view', $project);
        
        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        return $laborWork->steps()->orderBy('sort_order')->get();
    }

    public function store(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        \Log::info('Creating labor work step', [
            'project_id' => $project->id,
            'labor_work_id' => $laborWork->id,
            'timestamp' => now(),
        ]);
        $startTime = microtime(true);

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'basis' => 'nullable|string|max:500',
                'input_data' => 'nullable|string|max:255',
                'hours' => 'required|numeric|min:0',
                'note' => 'nullable|string',
                'sort_order' => 'sometimes|integer|min:0',
            ]);

            \Log::info('Step validation passed', [
                'labor_work_id' => $laborWork->id,
                'validated_data' => $validated,
            ]);

            $validated['project_labor_work_id'] = $laborWork->id;

            // Set sort_order if not provided
            if (!isset($validated['sort_order'])) {
                $maxSortOrder = $laborWork->steps()->max('sort_order');
                \Log::debug('Max sort order found', ['max' => $maxSortOrder]);
                $validated['sort_order'] = ($maxSortOrder ?? 0) + 1;
            }

            \Log::info('Creating step with data', ['data' => $validated]);
            
            $step = ProjectLaborWorkStep::create($validated);
            
            $elapsed = microtime(true) - $startTime;
            \Log::info('Labor work step created successfully', [
                'step_id' => $step->id,
                'step_hours' => $step->hours,
                'elapsed_seconds' => $elapsed,
            ]);
            
            return response()->json($step, 201);
        } catch (\Exception $e) {
            $elapsed = microtime(true) - $startTime;
            \Log::error('Failed to create labor work step', [
                'project_id' => $project->id,
                'labor_work_id' => $laborWork->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'elapsed_seconds' => $elapsed,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to create step: ' . $e->getMessage(),
                'error_code' => $e->getCode()
            ], 500);
        }
    }

    public function show(Project $project, ProjectLaborWork $laborWork, int $step)
    {
        $this->authorize('view', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $stepModel = ProjectLaborWorkStep::where('id', $step)
            ->where('project_labor_work_id', $laborWork->id)
            ->first();

        if (!$stepModel) {
            return response()->json([
                'message' => 'Step not found',
                'step_id' => $step,
            ], 404);
        }

        return $stepModel;
    }

    public function update(Request $request, Project $project, ProjectLaborWork $laborWork, int $step)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $stepModel = ProjectLaborWorkStep::where('id', $step)
            ->where('project_labor_work_id', $laborWork->id)
            ->first();

        if (!$stepModel) {
            return response()->json([
                'message' => 'Step not found or already deleted',
                'step_id' => $step,
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'basis' => 'nullable|string|max:500',
            'input_data' => 'nullable|string|max:255',
            'hours' => 'sometimes|required|numeric|min:0',
            'note' => 'nullable|string',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $stepModel->update($validated);
        return $stepModel;
    }

    public function destroy(Project $project, ProjectLaborWork $laborWork, int $step)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $stepModel = ProjectLaborWorkStep::where('id', $step)
            ->where('project_labor_work_id', $laborWork->id)
            ->first();

        if (!$stepModel) {
            Log::info('Step already deleted or not found', [
                'project_id' => $project->id,
                'labor_work_id' => $laborWork->id,
                'step_id' => $step,
            ]);

            return response()->json([
                'message' => 'Step already deleted',
                'step_id' => $step,
            ], 200);
        }

        try {
            $start_time = microtime(true);
            $step_id = $stepModel->id;
            $step_title = $stepModel->title;
            
            $stepModel->delete();
            
            $elapsed = microtime(true) - $start_time;
            Log::info('Step deleted successfully', [
                'project_id' => $project->id,
                'labor_work_id' => $laborWork->id,
                'step_id' => $step_id,
                'step_title' => $step_title,
                'elapsed_seconds' => round($elapsed, 3),
                'status' => 204
            ]);
            
            return response()->noContent();
        } catch (\Exception $e) {
            Log::error('Failed to delete step', [
                'project_id' => $project->id,
                'labor_work_id' => $laborWork->id,
                'step_id' => $step_id ?? $step,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Failed to delete step'], 500);
        }
    }

    /**
     * Перестановка шагов
     */
    public function reorder(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'steps' => 'required|array',
            'steps.*.id' => 'required|integer',
            'steps.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['steps'] as $stepData) {
            ProjectLaborWorkStep::where('id', $stepData['id'])
                ->where('project_labor_work_id', $laborWork->id)
                ->update(['sort_order' => $stepData['sort_order']]);
        }

        return response()->json(['message' => 'Steps reordered successfully']);
    }

    /**
     * Replace all steps atomically (for AI decomposition)
     * PUT /api/projects/{project}/labor-works/{laborWork}/steps:replace
     */
    public function replaceAll(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'steps' => 'required|array|min:1|max:20',
            'steps.*.title' => 'required|string|max:255',
            'steps.*.basis' => 'nullable|string|max:500',
            'steps.*.input_data' => 'nullable|string|max:255',
            'steps.*.hours' => 'required|numeric|min:0',
            'steps.*.note' => 'nullable|string',
        ]);

        try {
            return \DB::transaction(function () use ($laborWork, $validated) {
                // Delete all existing steps
                $laborWork->steps()->delete();

                // Create new steps with sort_order
                $createdSteps = [];
                foreach ($validated['steps'] as $index => $stepData) {
                    $createdSteps[] = ProjectLaborWorkStep::create([
                        'project_labor_work_id' => $laborWork->id,
                        'title' => $stepData['title'],
                        'basis' => $stepData['basis'] ?? null,
                        'input_data' => $stepData['input_data'] ?? null,
                        'hours' => $stepData['hours'],
                        'note' => $stepData['note'] ?? null,
                        'sort_order' => $index,
                    ]);
                }

                Log::info('Steps replaced successfully', [
                    'labor_work_id' => $laborWork->id,
                    'steps_count' => count($createdSteps),
                ]);

                return response()->json([
                    'message' => 'Steps replaced successfully',
                    'steps' => $createdSteps,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to replace steps', [
                'labor_work_id' => $laborWork->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to replace steps'], 500);
        }
    }

    /**
     * Append steps to the end (for AI decomposition)
     * POST /api/projects/{project}/labor-works/{laborWork}/steps:append
     */
    public function appendAll(Request $request, Project $project, ProjectLaborWork $laborWork)
    {
        $this->authorize('update', $project);

        if ($laborWork->project_id !== $project->id) {
            abort(404);
        }

        $validated = $request->validate([
            'steps' => 'required|array|min:1|max:20',
            'steps.*.title' => 'required|string|max:255',
            'steps.*.basis' => 'nullable|string|max:500',
            'steps.*.input_data' => 'nullable|string|max:255',
            'steps.*.hours' => 'required|numeric|min:0',
            'steps.*.note' => 'nullable|string',
        ]);

        try {
            return \DB::transaction(function () use ($laborWork, $validated) {
                // Get current max sort_order
                $maxSortOrder = $laborWork->steps()->max('sort_order') ?? -1;

                // Create new steps with incremented sort_order
                $createdSteps = [];
                foreach ($validated['steps'] as $index => $stepData) {
                    $createdSteps[] = ProjectLaborWorkStep::create([
                        'project_labor_work_id' => $laborWork->id,
                        'title' => $stepData['title'],
                        'basis' => $stepData['basis'] ?? null,
                        'input_data' => $stepData['input_data'] ?? null,
                        'hours' => $stepData['hours'],
                        'note' => $stepData['note'] ?? null,
                        'sort_order' => $maxSortOrder + 1 + $index,
                    ]);
                }

                Log::info('Steps appended successfully', [
                    'labor_work_id' => $laborWork->id,
                    'steps_count' => count($createdSteps),
                ]);

                return response()->json([
                    'message' => 'Steps appended successfully',
                    'steps' => $createdSteps,
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Failed to append steps', [
                'labor_work_id' => $laborWork->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to append steps'], 500);
        }
    }
}
