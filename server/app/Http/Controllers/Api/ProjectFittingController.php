<?php
// app/Http/Controllers/Api/ProjectFittingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectFitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectFittingController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        return $project->fittings;
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'article' => 'nullable|string|max:255',
            'unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'unit_price' => 'required|numeric|min:0',
        ]);

        $validated['project_id'] = $project->id;
        $fitting = ProjectFitting::create($validated);
        return response()->json($fitting, 201);
    }

    public function show(Project $project, ProjectFitting $fitting)
    {
        if ($fitting->project_id !== $project->id) abort(404);
        $this->authorize('view', $project);
        return $fitting;
    }

    public function update(Request $request, Project $project, ProjectFitting $fitting)
    {
        // Reload the fitting with its project relation to ensure it's available
        $fitting = ProjectFitting::with('project')->findOrFail($fitting->id);
        
        if ($fitting->project_id !== $project->id) abort(404);
        
        Log::info('ProjectFittingController::update (nested route)', [
            'fitting_id' => $fitting->id,
            'project_id' => $fitting->project?->id,
            'project_user_id' => $fitting->project?->user_id,
            'auth_user_id' => auth()->id(),
        ]);
        
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'article' => 'nullable|string|max:255',
            'unit' => 'sometimes|required|string|max:50',
            'quantity' => 'sometimes|required|numeric|min:0',
            'unit_price' => 'sometimes|required|numeric|min:0',
        ]);

        $fitting->update($validated);
        return $fitting;
    }

    public function destroy(Project $project, ProjectFitting $fitting)
    {
        // Reload the fitting with its project relation to ensure it's available
        $fitting = ProjectFitting::with('project')->findOrFail($fitting->id);
        
        if ($fitting->project_id !== $project->id) abort(404);
        
        Log::info('ProjectFittingController::destroy (nested route)', [
            'fitting_id' => $fitting->id,
            'project_id' => $fitting->project?->id,
            'project_user_id' => $fitting->project?->user_id,
            'auth_user_id' => auth()->id(),
        ]);
        
        $this->authorize('update', $project);
        $fitting->delete();
        return response()->noContent();
    }

    // Top-level handlers for routes like /api/project-fittings/{id}
    public function showById(ProjectFitting $fitting)
    {
        $fitting->load('project');
        return $fitting;
    }

    public function updateById(Request $request, ProjectFitting $fitting)
    {
        // Явно загружаем связь через свежий запрос
        $fitting = ProjectFitting::with('project')->findOrFail($fitting->id);
        $project = $fitting->project;
        
        Log::info('ProjectFittingController::updateById attempt', [
            'fitting_id' => $fitting->id,
            'project_id' => $project?->id,
            'project_user_id' => $project?->user_id,
            'auth_user_id' => auth()->id(),
            'auth_user' => auth()->user() ? auth()->user()->name : 'null',
        ]);
        
        if (!$project) {
            Log::error('ProjectFittingController::updateById - project not found', [
                'fitting_id' => $fitting->id,
                'fitting_data' => $fitting->toArray(),
            ]);
            abort(404, 'Project not found for fitting');
        }
        
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'article' => 'nullable|string|max:255',
            'unit' => 'sometimes|required|string|max:50',
            'quantity' => 'sometimes|required|numeric|min:0',
            'unit_price' => 'sometimes|required|numeric|min:0',
        ]);

        $fitting->update($validated);
        return $fitting;
    }
    public function destroyById(ProjectFitting $fitting)
    {
        // Явно загружаем связь через свежий запрос
        $fitting = ProjectFitting::with('project')->findOrFail($fitting->id);
        $project = $fitting->project;
        
        if (!$project) {
            Log::error('ProjectFittingController::destroyById - project not found', [
                'fitting_id' => $fitting->id,
            ]);
            abort(404, 'Project not found for fitting');
        }
        
        $this->authorize('update', $project);
        $fitting->delete();
        return response()->noContent();
    }
}

