<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectProfileRate;

class LaborRateDiagnosticsService
{
    public function analyze(Project $project): array
    {
        $profileRates = ProjectProfileRate::query()
            ->where('project_id', $project->id)
            ->with(['profile:id,name', 'region'])
            ->orderBy('profile_id')
            ->orderByDesc('fixed_at')
            ->get();

        $works = ProjectLaborWork::query()
            ->where('project_id', $project->id)
            ->with([
                'positionProfile:id,name',
                'profileRate:id,project_id,profile_id,region_id,rate_fixed,fixed_at,is_locked,sources_snapshot',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $profileRatePayload = $profileRates->map(function (ProjectProfileRate $rate): array {
            $sourcesSnapshot = $this->normalizeSnapshotValue($rate->sources_snapshot);

            return [
                'id' => $rate->id,
                'profile_id' => $rate->profile_id,
                'profile_name' => $rate->profile?->name,
                'region_id' => $rate->region_id,
                'region_name' => $this->resolveRegionName($rate->region),
                'rate_fixed' => $rate->rate_fixed !== null ? (float) $rate->rate_fixed : null,
                'is_locked' => (bool) $rate->is_locked,
                'fixed_at' => $rate->fixed_at?->toIso8601String(),
                'sources_snapshot' => $sourcesSnapshot,
                'sources_count' => is_array($sourcesSnapshot) ? count($sourcesSnapshot) : null,
            ];
        })->values();

        $worksPayload = $works->map(function (ProjectLaborWork $work): array {
            $decodedSnapshot = $this->decodeRateSnapshot($work->rate_snapshot);

            return [
                'id' => $work->id,
                'title' => $work->title,
                'position_profile_id' => $work->position_profile_id,
                'position_profile_name' => $work->positionProfile?->name,
                'rate_per_hour' => $work->rate_per_hour !== null ? (float) $work->rate_per_hour : null,
                'cost_total' => $work->cost_total !== null ? (float) $work->cost_total : null,
                'project_profile_rate_id' => $work->project_profile_rate_id,
                'has_rate_snapshot' => $decodedSnapshot !== null,
                'snapshot_summary' => $this->buildSnapshotSummary($decodedSnapshot),
            ];
        })->values();

        $resolution = $works->map(function (ProjectLaborWork $work) use ($project): array {
            $profileRate = $work->profileRate;
            $effectiveRate = null;
            $source = 'none';

            if ($work->rate_per_hour !== null) {
                $effectiveRate = (float) $work->rate_per_hour;
                $source = 'work_rate';
            } elseif ($profileRate && $profileRate->rate_fixed !== null) {
                $effectiveRate = (float) $profileRate->rate_fixed;
                $source = 'profile_rate';
            } elseif ($project->normohour_rate !== null) {
                $projectNormohourRate = (float) $project->normohour_rate;
                $effectiveRate = (float) $projectNormohourRate;
                $source = 'project_fallback';
            }

            return [
                'work_id' => $work->id,
                'title' => $work->title,
                'position_profile_id' => $work->position_profile_id,
                'position_profile_name' => $work->positionProfile?->name,
                'effective_rate' => $effectiveRate,
                'source' => $source,
                'work_rate_per_hour' => $work->rate_per_hour !== null ? (float) $work->rate_per_hour : null,
                'project_profile_rate_id' => $work->project_profile_rate_id,
                'project_profile_rate_value' => $profileRate && $profileRate->rate_fixed !== null ? (float) $profileRate->rate_fixed : null,
                'project_normohour_rate' => $project->normohour_rate !== null ? (float) $project->normohour_rate : null,
            ];
        })->values();

        return [
            'project_id' => $project->id,
            'project_level' => [
                'normohour_rate' => $project->normohour_rate !== null ? (float) $project->normohour_rate : null,
                'region_id' => $project->region_id,
                'region_name' => $this->resolveRegionName($project->region),
            ],
            'profile_rates' => $profileRatePayload,
            'works' => $worksPayload,
            'resolution' => $resolution,
        ];
    }

    private function decodeRateSnapshot(mixed $snapshot): ?array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (!is_string($snapshot) || trim($snapshot) === '') {
            return null;
        }

        $decoded = json_decode($snapshot, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function buildSnapshotSummary(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $sources = $snapshot['sources_snapshot'] ?? $snapshot['sources'] ?? null;

        return [
            'type' => $snapshot['type'] ?? null,
            'method' => $snapshot['method'] ?? null,
            'rate_per_hour' => isset($snapshot['rate_per_hour']) ? (float) $snapshot['rate_per_hour'] : null,
            'rate_fixed' => isset($snapshot['rate_fixed']) ? (float) $snapshot['rate_fixed'] : null,
            'calculated_at' => $snapshot['calculated_at'] ?? $snapshot['applied_at'] ?? $snapshot['fixed_at'] ?? null,
            'locked_reason' => $snapshot['locked_reason'] ?? null,
            'error' => $snapshot['error'] ?? $snapshot['error_message'] ?? null,
            'sources_count' => is_array($sources) ? count($sources) : null,
        ];
    }

    private function normalizeSnapshotValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function resolveRegionName(mixed $region): ?string
    {
        if (!$region) {
            return null;
        }

        return $region->name ?? $region->region_name ?? null;
    }
}
