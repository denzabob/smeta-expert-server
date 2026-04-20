<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\LaborCostCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectLaborCostController extends Controller
{
    public function __construct(
        private readonly LaborCostCalculationService $calculationService,
    ) {
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $payload = $this->calculationService->calculate($project->loadMissing('region'), $request->user());

        return response()->json($payload);
    }
}
