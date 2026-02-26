<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectManualOperation;
use Illuminate\Http\Request;

class ProjectOperationController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'operation_id' => 'required|exists:operations,id',
            'quantity' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $validated['project_id'] = $project->id;
        $m = ProjectManualOperation::create($validated);
        return response()->json($m, 201);
    }
}
