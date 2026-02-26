<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrustScoreService
{
    /**
     * Recalculate trust score for a material.
     * Deterministic formula based on data quality indicators.
     *
     * Components:
     *   +40 if >=1 verified observation within last 30 days
     *   +20 if >=2 independent source URLs (different domains) within last 60 days
     *   +15 if name, article, unit, type filled AND data_origin != 'manual' or is_verified
     *   +10 if latest observation has snapshot_path or snapshot_json
     *   -25 if last 3 parses all failed/blocked
     *   -20 if latest price older than 90 days
     *
     * Trust levels:
     *   verified: score >= 70
     *   partial:  score 30..69
     *   unverified: score < 30
     */
    public function recalculate(Material $material): Material
    {
        $score = 0;

        $observations = MaterialPriceHistory::where('material_id', $material->id)
            ->orderByDesc('observed_at')
            ->get();

        // +40: at least 1 verified observation within 30 days
        $recentVerified = $observations->filter(function ($obs) {
            return $obs->is_verified && $obs->observed_at && $obs->observed_at->gte(now()->subDays(30));
        });
        if ($recentVerified->isNotEmpty()) {
            $score += 40;
        }

        // +20: >=2 independent source URLs (different domains) within 60 days
        $recentObs = $observations->filter(function ($obs) {
            return $obs->observed_at && $obs->observed_at->gte(now()->subDays(60)) && $obs->source_url;
        });
        $domains = $recentObs->map(function ($obs) {
            return parse_url($obs->source_url, PHP_URL_HOST);
        })->filter()->unique();
        if ($domains->count() >= 2) {
            $score += 20;
        }

        // +15: all key fields filled AND (data_origin != 'manual' or has verified observation)
        $hasCompleteData = !empty($material->name)
            && !empty($material->article)
            && !empty($material->unit)
            && !empty($material->type);
        $hasVerifiedOrigin = $material->data_origin !== Material::ORIGIN_MANUAL
            || $observations->where('is_verified', true)->isNotEmpty();
        if ($hasCompleteData && $hasVerifiedOrigin) {
            $score += 15;
        }

        // +10: latest observation has snapshot
        $latestObs = $observations->first();
        if ($latestObs && ($latestObs->snapshot_path || $latestObs->screenshot_path)) {
            $score += 10;
        }

        // -25: last 3 parses all failed/blocked
        if (in_array($material->last_parse_status, [Material::PARSE_FAILED, Material::PARSE_BLOCKED])) {
            // Check metadata for parse history
            $meta = $material->metadata ?? [];
            $failStreak = $meta['parse_fail_streak'] ?? 0;
            if ($failStreak >= 3) {
                $score -= 25;
            }
        }

        // -20: latest price older than 90 days
        if ($latestObs) {
            $observedAt = $latestObs->observed_at ?? $latestObs->created_at;
            if ($observedAt && $observedAt->lt(now()->subDays(90))) {
                $score -= 20;
            }
        } else {
            // No observations at all
            $score -= 20;
        }

        // Clamp score 0..100
        $score = max(0, min(100, $score));

        // Determine trust level
        if ($score >= 70) {
            $trustLevel = Material::TRUST_VERIFIED;
        } elseif ($score >= 30) {
            $trustLevel = Material::TRUST_PARTIAL;
        } else {
            $trustLevel = Material::TRUST_UNVERIFIED;
        }

        $material->trust_score = $score;
        $material->trust_level = $trustLevel;
        $material->save();

        return $material;
    }

    /**
     * Batch recalculate trust scores for multiple materials.
     */
    public function recalculateBatch(array $materialIds): void
    {
        $materials = Material::whereIn('id', $materialIds)->get();
        foreach ($materials as $material) {
            try {
                $this->recalculate($material);
            } catch (\Throwable $e) {
                Log::warning("TrustScore recalculation failed for material #{$material->id}: {$e->getMessage()}");
            }
        }
    }
}
