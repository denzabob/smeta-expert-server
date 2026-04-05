<?php

namespace App\Services;

use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use Carbon\Carbon;

/**
 * Evaluates whether a material's price confirmation is fresh
 * based on existing evidence records with proof assets.
 */
class MaterialConfirmationService
{
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_STALE = 'stale';
    public const STATE_MISSING = 'missing';

    public const DEFAULT_FRESHNESS_DAYS = 7;

    public function __construct(
        private UrlNormalizer $urlNormalizer,
    ) {}

    /**
     * Evaluate freshness for a given source URL + cost component.
     *
     * @return array{state: string, confirmed_at: string|null, days_ago: int|null, record_id: int|null}
     */
    public function evaluate(
        ?string $sourceUrl,
        string $costComponent,
        int $freshnessDays = self::DEFAULT_FRESHNESS_DAYS,
    ): array {
        if (empty($sourceUrl)) {
            return self::missing();
        }

        $normalizedUrl = $this->urlNormalizer->normalize($sourceUrl);

        // Find the latest non-rejected evidence record matching source_url + cost_component
        // that has at least one proof asset (screenshot).
        $record = EvidenceRecord::where('source_url', $normalizedUrl)
            ->where('cost_component', $costComponent)
            ->where('verification_status', '!=', 'rejected')
            ->whereHas('assets', function ($q) {
                $q->where('asset_type', 'screenshot');
            })
            ->orderByDesc('created_at')
            ->first();

        if (!$record) {
            return self::missing();
        }

        $confirmedAt = $record->observed_at ?? $record->created_at;
        $daysAgo = (int) Carbon::parse($confirmedAt)->diffInDays(now());

        if ($daysAgo <= $freshnessDays) {
            return [
                'state' => self::STATE_CONFIRMED,
                'confirmed_at' => Carbon::parse($confirmedAt)->toIso8601String(),
                'days_ago' => $daysAgo,
                'record_id' => $record->id,
            ];
        }

        return [
            'state' => self::STATE_STALE,
            'confirmed_at' => Carbon::parse($confirmedAt)->toIso8601String(),
            'days_ago' => $daysAgo,
            'record_id' => $record->id,
        ];
    }

    /**
     * Check if there is a fresh confirmation for auto-resolution purposes.
     */
    public function isFresh(
        ?string $sourceUrl,
        string $costComponent,
        int $freshnessDays = self::DEFAULT_FRESHNESS_DAYS,
    ): bool {
        $result = $this->evaluate($sourceUrl, $costComponent, $freshnessDays);
        return $result['state'] === self::STATE_CONFIRMED;
    }

    /**
     * Get the fresh evidence record for auto-resolution, or null.
     */
    public function getFreshRecord(
        ?string $sourceUrl,
        string $costComponent,
        int $freshnessDays = self::DEFAULT_FRESHNESS_DAYS,
    ): ?EvidenceRecord {
        $result = $this->evaluate($sourceUrl, $costComponent, $freshnessDays);
        if ($result['state'] === self::STATE_CONFIRMED && $result['record_id']) {
            return EvidenceRecord::find($result['record_id']);
        }
        return null;
    }

    /**
     * Find a fresh equivalent proof record that also matches the observed price.
     *
     * Used by Chrome one-click dedup to avoid creating redundant same-day snapshots
     * when the price hasn't materially changed.  Returns the existing record if ALL
     * conditions hold:
     *  - same normalized source_url + cost_component
     *  - non-rejected, with a screenshot asset
     *  - created within freshnessDays
     *  - observed_price matches within ±1% (or both null)
     */
    public function getFreshEquivalentRecord(
        ?string $sourceUrl,
        string $costComponent,
        ?float $observedPrice,
        int $freshnessDays = self::DEFAULT_FRESHNESS_DAYS,
    ): ?EvidenceRecord {
        $record = $this->getFreshRecord($sourceUrl, $costComponent, $freshnessDays);
        if (!$record) {
            return null;
        }

        // Price equivalence: both null → equivalent; one null → not equivalent
        if ($observedPrice === null && $record->observed_price === null) {
            return $record;
        }
        if ($observedPrice === null || $record->observed_price === null) {
            return null;
        }

        // ±1% tolerance for floating-point / minor rounding differences
        $tolerance = max(abs($record->observed_price) * 0.01, 0.01);
        if (abs($observedPrice - (float) $record->observed_price) <= $tolerance) {
            return $record;
        }

        return null; // price changed materially — new snapshot needed
    }

    private static function missing(): array
    {
        return [
            'state' => self::STATE_MISSING,
            'confirmed_at' => null,
            'days_ago' => null,
            'record_id' => null,
        ];
    }
}
