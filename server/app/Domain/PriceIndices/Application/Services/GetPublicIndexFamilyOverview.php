<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class GetPublicIndexFamilyOverview
{
    /**
     * @return array{
     *     series_count:int,
     *     period_from:?CarbonImmutable,
     *     period_to:?CarbonImmutable,
     *     source_published_at:?CarbonImmutable,
     *     last_modified_at:?CarbonImmutable,
     *     examples:Collection<int, StatisticalPublicSeriesPage>,
     *     source_url:?string
     * }
     */
    public function __construct(private readonly PublicIndexFamilyRegistry $families) {}

    public function execute(string $familyCode, int $examplesLimit = 8): array
    {
        $family = $this->families->get($familyCode);
        $summary = $this->indexablePages($familyCode)
            ->select([])
            ->selectRaw('COUNT(*) as series_count')
            ->selectRaw('MIN(period_from) as period_from')
            ->selectRaw('MAX(period_to) as period_to')
            ->selectRaw('MAX(source_published_at) as source_published_at')
            ->selectRaw('MAX(generated_at) as last_modified_at')
            ->firstOrFail();

        $examples = $this->indexablePages($familyCode)
            ->addSelect([
                'latest_value' => StatisticalObservation::query()
                    ->select('value')
                    ->whereColumn('statistical_observations.import_id', 'statistical_public_series_pages.import_id')
                    ->whereColumn('statistical_observations.series_id', 'statistical_public_series_pages.series_id')
                    ->whereColumn('statistical_observations.period_start', 'statistical_public_series_pages.period_to')
                    ->limit(1),
            ])
            ->with(['classifierItem:id,item_code,name', 'dataset:id,code'])
            ->orderBy('slug')
            ->limit(max(1, min($examplesLimit, 12)))
            ->get();

        return [
            'series_count' => (int) $summary->getAttribute('series_count'),
            'period_from' => $this->date($summary->getAttribute('period_from')),
            'period_to' => $this->date($summary->getAttribute('period_to')),
            'source_published_at' => $this->date($summary->getAttribute('source_published_at')),
            'last_modified_at' => $this->date($summary->getAttribute('last_modified_at')),
            'examples' => $examples,
            'source_url' => $this->sourceUrl($familyCode),
            'family' => $family,
        ];
    }

    private function indexablePages(string $familyCode): Builder
    {
        $family = $this->families->get($familyCode);
        $datasetSql = $this->families->datasetSql($family, 'family_datasets.code');

        return StatisticalPublicSeriesPage::query()
            ->join('statistical_datasets as family_datasets', 'family_datasets.id', '=', 'statistical_public_series_pages.dataset_id')
            ->select('statistical_public_series_pages.*')
            ->whereRaw($datasetSql['sql'], $datasetSql['bindings'])
            ->where('statistical_public_series_pages.is_indexable', true)
            ->whereNotNull('statistical_public_series_pages.slug');
    }

    private function sourceUrl(string $familyCode): ?string
    {
        $pages = $this->indexablePages($familyCode)
            ->with('sourceFile.source:id,source_page_url')
            ->whereHas('sourceFile', function (Builder $files): void {
                $files->whereNotNull('source_url')
                    ->orWhereHas('source', fn (Builder $sources) => $sources->whereNotNull('source_page_url'));
            })
            ->orderByDesc('source_published_at')
            ->limit(20)
            ->get();

        foreach ($pages as $page) {
            $url = $page->sourceFile?->source?->source_page_url ?: $page->sourceFile?->source_url;
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                return $url;
            }
        }

        return null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
