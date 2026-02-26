<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectNormohourSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectNormohourSourceController extends Controller
{
    /**
     * Get all normohour sources for a project
     */
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        
        return $project->normohourSources()->get();
    }

    /**
     * Get a single normohour source
     */
    public function show(Project $project, ProjectNormohourSource $source)
    {
        $this->authorize('view', $project);

        // Verify that the source belongs to this project
        if ($source->project_id !== $project->id) {
            return response()->json(['error' => 'Source not found'], 404);
        }

        return response()->json($source);
    }

    /**
     * Create a new normohour source
     */
    public function store(Project $project, Request $request)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'source' => 'required|string|max:255',
            'position_profile' => 'nullable|string|max:255',
            'salary_range' => 'nullable|string|max:255',
            'period' => 'nullable|string|max:50',
            'link' => 'nullable|url|max:1000',
            'note' => 'nullable|string|max:1000',
        ]);

        $validated['project_id'] = $project->id;
        $source = ProjectNormohourSource::create($validated);

        return response()->json($source, 201);
    }

    /**
     * Update a normohour source
     */
    public function update(Project $project, ProjectNormohourSource $source, Request $request)
    {
        $this->authorize('update', $project);

        // Verify that the source belongs to this project
        if ($source->project_id !== $project->id) {
            return response()->json(['error' => 'Source not found'], 404);
        }

        $validated = $request->validate([
            'source' => 'required|string|max:255',
            'position_profile' => 'nullable|string|max:255',
            'salary_range' => 'nullable|string|max:255',
            'period' => 'nullable|string|max:50',
            'link' => 'nullable|url|max:1000',
            'note' => 'nullable|string|max:1000',
        ]);

        $source->update($validated);

        return response()->json($source);
    }

    /**
     * Delete a normohour source
     */
    public function destroy(Project $project, ProjectNormohourSource $source)
    {
        $this->authorize('update', $project);

        // Verify that the source belongs to this project
        if ($source->project_id !== $project->id) {
            return response()->json(['error' => 'Source not found'], 404);
        }

        $source->delete();

        return response()->json(null, 204);
    }
}
