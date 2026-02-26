<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectManualOperation;
use Illuminate\Http\Request;

class ProjectManualOperationController extends Controller
{
    public function update(Request $request, ProjectManualOperation $projectManualOperation)
    {
        $operation = $projectManualOperation;
        
        $project = $operation->project;
        if (!$project) {
            return response()->json([
                'message' => 'Project not found for operation',
            ], 404);
        }

        $this->authorize('update', $project);

        $validated = $request->validate([
            'quantity' => 'sometimes|required|numeric|min:0.01',
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $operation->update($validated);
            \Log::info('Manual operation updated', [
                'operation_id' => $operation->id,
                'data' => $validated,
            ]);
            return response()->json($operation);
        } catch (\Exception $e) {
            \Log::error('Failed to update operation', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to update operation: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, ProjectManualOperation $projectManualOperation)
    {
        $operation = $projectManualOperation;
        
        $project = $operation->project;
        if (!$project) {
            return response()->json([
                'message' => 'Project not found for operation',
            ], 404);
        }

        $this->authorize('update', $project);

        try {
            $operation->delete();
            \Log::info('Manual operation deleted', [
                'operation_id' => $operation->id,
            ]);
            return response()->noContent();
        } catch (\Exception $e) {
            \Log::error('Failed to delete operation', [
                'operation_id' => $operation->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to delete operation: ' . $e->getMessage()
            ], 500);
        }
    }
}
