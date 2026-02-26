<?php
// app/Http/Controllers/Api/ExpenseController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function store(Request $request, $projectId)
    {
        $validated = $request->validate([
            'type' => 'required|string|max:255',
            'cost' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $validated['project_id'] = $projectId;
        $expense = Expense::create($validated);
        return response()->json($expense, 201);
    }

    // update, destroy аналогично
}
