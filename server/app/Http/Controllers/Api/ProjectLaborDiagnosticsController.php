<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\LaborRateDiagnosticsService;
use Illuminate\Http\JsonResponse;

class ProjectLaborDiagnosticsController extends Controller
{
    public function __construct(
        private readonly LaborRateDiagnosticsService $diagnosticsService
    ) {
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json(
            $this->diagnosticsService->analyze($project)
        );
    }
}
