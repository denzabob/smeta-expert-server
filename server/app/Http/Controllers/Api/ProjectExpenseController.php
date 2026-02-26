<?php
// app/Http/Controllers/Api/ProjectExpenseController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Expense;
use Illuminate\Http\Request;

class ProjectExpenseController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('view', $project);
        return $project->expenses;
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $validated['project_id'] = $project->id;
        $expense = Expense::create($validated);
        return response()->json($expense, 201);
    }

    public function show(Project $project, Expense $expense)
    {
        if ($expense->project_id !== $project->id) abort(404);
        $this->authorize('view', $project);
        return $expense;
    }

    public function update(Request $request, Project $project, Expense $expense)
    {
        if ($expense->project_id !== $project->id) abort(404);
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $expense->update($validated);
        return $expense;
    }

    public function destroy(Project $project, Expense $expense)
    {
        if ($expense->project_id !== $project->id) abort(404);
        $this->authorize('update', $project);
        $expense->delete();
        return response()->noContent();
    }
}
