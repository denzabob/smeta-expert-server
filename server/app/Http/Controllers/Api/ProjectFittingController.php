<?php
// app/Http/Controllers/Api/ProjectFittingController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectFitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProjectFittingController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        return $project->fittings()->with('material')->get();
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'material_id' => [
                'required',
                'integer',
                Rule::exists('materials', 'id')->where(fn ($query) => $query->where('type', Material::TYPE_HARDWARE)),
            ],
            'quantity' => 'required|numeric|min:0',
        ]);

        $material = Material::query()
            ->where('type', Material::TYPE_HARDWARE)
            ->findOrFail((int) $validated['material_id']);

        $fitting = ProjectFitting::create([
            'project_id' => $project->id,
            'material_id' => $material->id,
            'name' => $material->name,
            'article' => $material->article,
            'unit' => $material->unit ?: 'шт',
            'quantity' => (float) $validated['quantity'],
            'unit_price' => (float) ($material->price_per_unit ?? 0),
            'source_url' => $material->source_url,
        ]);

        return response()->json($fitting->load('material'), 201);
    }

    public function show(Project $project, ProjectFitting $fitting)
    {
        if ($fitting->project_id !== $project->id) abort(404);
        $this->authorize('view', $project);
        return $fitting->load('material');
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
            'material_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('materials', 'id')->where(fn ($query) => $query->where('type', Material::TYPE_HARDWARE)),
            ],
            'quantity' => 'sometimes|required|numeric|min:0',
        ]);

        $materialId = isset($validated['material_id'])
            ? (int) $validated['material_id']
            : (int) $fitting->material_id;

        if ($materialId <= 0) {
            return response()->json([
                'message' => 'material_id обязателен для фурнитуры',
            ], 422);
        }

        $material = Material::query()
            ->where('type', Material::TYPE_HARDWARE)
            ->findOrFail($materialId);

        $fitting->update([
            'material_id' => $material->id,
            'name' => $material->name,
            'article' => $material->article,
            'unit' => $material->unit ?: 'шт',
            'quantity' => array_key_exists('quantity', $validated)
                ? (float) $validated['quantity']
                : (float) $fitting->quantity,
            'unit_price' => (float) ($material->price_per_unit ?? 0),
            'source_url' => $material->source_url,
        ]);

        return $fitting->load('material');
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
        $fitting->load(['project', 'material']);
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
            'material_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('materials', 'id')->where(fn ($query) => $query->where('type', Material::TYPE_HARDWARE)),
            ],
            'quantity' => 'sometimes|required|numeric|min:0',
        ]);

        $materialId = isset($validated['material_id'])
            ? (int) $validated['material_id']
            : (int) $fitting->material_id;

        if ($materialId <= 0) {
            return response()->json([
                'message' => 'material_id обязателен для фурнитуры',
            ], 422);
        }

        $material = Material::query()
            ->where('type', Material::TYPE_HARDWARE)
            ->findOrFail($materialId);

        $fitting->update([
            'material_id' => $material->id,
            'name' => $material->name,
            'article' => $material->article,
            'unit' => $material->unit ?: 'шт',
            'quantity' => array_key_exists('quantity', $validated)
                ? (float) $validated['quantity']
                : (float) $fitting->quantity,
            'unit_price' => (float) ($material->price_per_unit ?? 0),
            'source_url' => $material->source_url,
        ]);

        return $fitting->load('material');
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

