<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MaterialDeduplicationService
{
    /**
     * Normalize a URL for dedup comparison.
     * Removes utm_*, anchors, trailing slashes, lowercase host+path.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        if (!$url) return null;

        $parsed = parse_url(trim($url));
        if (!$parsed || empty($parsed['host'])) return null;

        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $host = strtolower(preg_replace('/^www\./', '', $parsed['host']));
        $path = rtrim($parsed['path'] ?? '', '/');

        // Parse and filter query params
        $query = '';
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            // Remove tracking params
            $trackingParams = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'fbclid', 'gclid', 'yclid', 'ymclid', 'ref', 'referer', '_openstat'];
            foreach ($trackingParams as $tp) {
                unset($params[$tp]);
            }
            if (!empty($params)) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        return "{$scheme}://{$host}{$path}{$query}";
    }

    /**
     * Find potential duplicates for a material being added.
     * Returns collection of candidate materials with match reason.
     *
     * @param string|null $url
     * @param string|null $article
     * @param string|null $name
     * @param string|null $unit
     * @param string $type
     * @return Collection  [{material: Material, reason: string, confidence: string}]
     */
    public function findDuplicates(?string $url, ?string $article, ?string $name, ?string $unit, string $type): Collection
    {
        $candidates = collect();

        // 1. Exact URL match (highest confidence)
        if ($url) {
            $normalizedUrl = self::normalizeUrl($url);
            if ($normalizedUrl) {
                // Check materials.source_url
                $bySourceUrl = Material::where('is_active', true)
                    ->where('source_url', 'LIKE', '%' . parse_url($normalizedUrl, PHP_URL_HOST) . '%')
                    ->get()
                    ->filter(fn($m) => self::normalizeUrl($m->source_url) === $normalizedUrl);

                foreach ($bySourceUrl as $m) {
                    $candidates->push(['material' => $m, 'reason' => 'exact_url', 'confidence' => 'high']);
                }

                // Check material_price_histories.source_url
                if ($candidates->isEmpty()) {
                    $historyMatches = MaterialPriceHistory::where('source_url', 'LIKE', '%' . parse_url($normalizedUrl, PHP_URL_HOST) . '%')
                        ->get()
                        ->filter(fn($h) => self::normalizeUrl($h->source_url) === $normalizedUrl)
                        ->pluck('material_id')
                        ->unique();

                    if ($historyMatches->isNotEmpty()) {
                        $materials = Material::where('is_active', true)
                            ->whereIn('id', $historyMatches)
                            ->get();
                        foreach ($materials as $m) {
                            $candidates->push(['material' => $m, 'reason' => 'url_in_history', 'confidence' => 'high']);
                        }
                    }
                }
            }
        }

        // 2. Article + type match (high confidence)
        if ($article && !$candidates->where('confidence', 'high')->isNotEmpty()) {
            $byArticle = Material::where('is_active', true)
                ->where('article', $article)
                ->where('type', $type)
                ->get();

            foreach ($byArticle as $m) {
                if (!$candidates->pluck('material.id')->contains($m->id)) {
                    $candidates->push(['material' => $m, 'reason' => 'article_type', 'confidence' => 'high']);
                }
            }
        }

        // 3. Normalized name + unit + type (soft/medium confidence)
        if ($name) {
            $searchName = Material::normalizeSearchName($name);
            $bySoftMatch = Material::where('is_active', true)
                ->where('search_name', $searchName)
                ->where('type', $type)
                ->when($unit, fn($q) => $q->where('unit', $unit))
                ->limit(10)
                ->get();

            foreach ($bySoftMatch as $m) {
                if (!$candidates->pluck('material.id')->contains($m->id)) {
                    $candidates->push(['material' => $m, 'reason' => 'name_unit_type', 'confidence' => 'medium']);
                }
            }
        }

        return $candidates;
    }

    /**
     * Merge duplicate material into the primary one.
     * Transfers all price histories and library entries, then deactivates the duplicate.
     *
     * @param int $primaryId  Main material to keep
     * @param int $duplicateId  Material to merge and deactivate
     * @return Material  The primary material
     */
    public function merge(int $primaryId, int $duplicateId): Material
    {
        $primary = Material::findOrFail($primaryId);
        $duplicate = Material::findOrFail($duplicateId);

        if ($primaryId === $duplicateId) {
            throw new \InvalidArgumentException('Cannot merge a material with itself.');
        }

        // Transfer all price histories
        MaterialPriceHistory::where('material_id', $duplicateId)
            ->update(['material_id' => $primaryId]);

        // Transfer library entries (skip if user already has primary)
        $existingLibraryUserIds = \App\Models\UserMaterialLibrary::where('material_id', $primaryId)
            ->pluck('user_id');

        \App\Models\UserMaterialLibrary::where('material_id', $duplicateId)
            ->whereNotIn('user_id', $existingLibraryUserIds)
            ->update(['material_id' => $primaryId]);

        // Remove remaining duplicate library entries
        \App\Models\UserMaterialLibrary::where('material_id', $duplicateId)->delete();

        // Deactivate duplicate
        $metadata = $duplicate->metadata ?? [];
        $metadata['merged_into'] = $primaryId;
        $metadata['merged_at'] = now()->toIso8601String();
        $duplicate->is_active = false;
        $duplicate->metadata = $metadata;
        $duplicate->save();

        Log::info("Material #{$duplicateId} merged into #{$primaryId}");

        return $primary->fresh();
    }
}
