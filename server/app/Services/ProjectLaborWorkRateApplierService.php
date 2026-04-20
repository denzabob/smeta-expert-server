<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProjectLaborWorkRateApplierService
{
    public function __construct(
        private readonly LaborCostCalculationService $calculationService,
    ) {
    }

    public function apply(Project $project): void
    {
        $project->loadMissing('user', 'laborWorks');

        if (!$project->user) {
            throw new RuntimeException('Project owner is required to apply labor work rates.');
        }

        $result = $this->calculationService->calculate($project, $project->user);
        $profiles = $result['profiles'] ?? [];
        $rateMap = [];

        foreach ($profiles as $profile) {
            $profileId = isset($profile['labor_profile_id']) ? (int) $profile['labor_profile_id'] : null;
            $finalRate = $profile['model']['final_rate'] ?? null;

            if ($profileId && $finalRate !== null) {
                $rateMap[$profileId] = (float) $finalRate;
            }
        }

        /** @var ProjectLaborWork $work */
        foreach ($project->laborWorks as $work) {
            $profileId = $work->labor_profile_id ? (int) $work->labor_profile_id : null;

            if ($profileId !== null && array_key_exists($profileId, $rateMap)) {
                $rate = $rateMap[$profileId];

                $work->rate_per_hour = $rate;
                $work->cost_total = round($rate * (float) $work->hours, 2);
            } else {
                $work->rate_per_hour = null;
                $work->cost_total = null;

                Log::warning('missing_rate_for_profile', [
                    'project_id' => $project->id,
                    'work_id' => $work->id,
                    'labor_profile_id' => $profileId,
                ]);
            }

            // Hard cutover: remove stale legacy rate attribution from active runtime.
            $work->project_profile_rate_id = null;
            $work->rate_snapshot = null;

            if ($work->isDirty(['rate_per_hour', 'cost_total', 'project_profile_rate_id', 'rate_snapshot'])) {
                $work->saveQuietly();
            }
        }
    }
}
