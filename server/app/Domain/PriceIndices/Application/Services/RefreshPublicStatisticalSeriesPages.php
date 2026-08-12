<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicSeriesRefreshResult;
use App\Domain\PriceIndices\Application\Data\PublicStatisticalSeriesSnapshot;
use App\Domain\PriceIndices\Application\Support\PublicIndexSlug;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RefreshPublicStatisticalSeriesPages
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private readonly BuildPublicStatisticalSeriesSnapshot $builder,
        private readonly PublicIndexSlug $slugs,
    ) {
    }

    public function execute(
        ?string $datasetSelector = null,
        ?string $seriesSelector = null,
        ?int $limit = null,
        bool $dryRun = false,
    ): PublicSeriesRefreshResult {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException('limit must be a positive integer.');
        }

        $pointers = StatisticalDatasetActiveImport::query()
            ->whereHas('dataset', function (Builder $query) use ($datasetSelector): void {
                $query->where('is_enabled', true);
                if ($datasetSelector !== null) {
                    $query->where(function (Builder $dataset) use ($datasetSelector): void {
                        $dataset->where('code', $datasetSelector)->orWhere('public_id', $datasetSelector);
                    });
                }
            })
            ->with(['dataset', 'import.dataset', 'import.sourceFile'])
            ->orderBy('dataset_id')
            ->get();

        if ($datasetSelector !== null && $pointers->isEmpty()) {
            throw new InvalidArgumentException('No enabled dataset with an active publication matches --dataset.');
        }

        $totals = [
            'series_scanned' => 0,
            'indexable' => 0,
            'non_indexable' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'stale' => 0,
        ];
        $datasets = [];

        foreach ($pointers as $pointer) {
            $import = $pointer->import;
            if ($import === null || $import->status !== StatisticalImportStatus::Published) {
                throw new InvalidArgumentException('Active import pointer does not reference a published import.');
            }

            $membership = StatisticalSeries::query()
                ->select(['id', 'classifier_item_id'])
                ->where('dataset_id', $pointer->dataset_id)
                ->whereHas('observations', fn (Builder $query) => $query->where('import_id', $import->id))
                ->with('classifierItem:id,item_code')
                ->orderBy('id')
                ->get();

            $activeSeriesIds = $membership->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $slugCounts = [];
            foreach ($membership as $member) {
                $slug = $this->slugs->fromItemCode((string) $member->classifierItem?->item_code);
                if ($slug !== null) {
                    $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
                }
            }

            $slugOwners = StatisticalPublicSeriesPage::query()
                ->where('dataset_id', $pointer->dataset_id)
                ->whereNotNull('slug')
                ->pluck('series_id', 'slug')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $query = StatisticalSeries::query()
                ->where('dataset_id', $pointer->dataset_id)
                ->whereHas('observations', fn (Builder $observations) => $observations->where('import_id', $import->id))
                ->with(['classifierItem', 'indicator', 'territory'])
                ->orderBy('id');

            if ($seriesSelector !== null) {
                $query->where(function (Builder $series) use ($seriesSelector): void {
                    $series->where('public_id', $seriesSelector)
                        ->orWhereHas('classifierItem', fn (Builder $item) => $item->where('item_code', $seriesSelector));
                });
            }

            $datasetCounts = [
                'scanned' => 0,
                'indexable' => 0,
                'non_indexable' => 0,
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'failed' => 0,
                'stale' => 0,
            ];

            foreach ($query->lazyById(self::CHUNK_SIZE) as $series) {
                if ($limit !== null && $totals['series_scanned'] >= $limit) {
                    break;
                }

                $snapshot = $this->builder->execute($import, $series);
                if ($snapshot->slug !== null
                    && (($slugCounts[$snapshot->slug] ?? 0) > 1
                        || (isset($slugOwners[$snapshot->slug]) && $slugOwners[$snapshot->slug] !== $series->id))
                ) {
                    $snapshot = $snapshot->withStatus(PublicSeriesIndexabilityStatus::SlugCollision);
                    $datasetCounts['failed']++;
                    $totals['failed']++;
                }

                $datasetCounts['scanned']++;
                $totals['series_scanned']++;
                if ($snapshot->isIndexable()) {
                    $datasetCounts['indexable']++;
                    $totals['indexable']++;
                } else {
                    $datasetCounts['non_indexable']++;
                    $totals['non_indexable']++;
                }

                if (! $dryRun) {
                    $outcome = $this->persist($snapshot);
                    $datasetCounts[$outcome]++;
                    $totals[$outcome]++;
                    if ($snapshot->slug !== null) {
                        $slugOwners[$snapshot->slug] = $series->id;
                    }
                }
            }

            if (! $dryRun && $seriesSelector === null && $limit === null) {
                $stale = StatisticalPublicSeriesPage::query()
                    ->where('dataset_id', $pointer->dataset_id)
                    ->when($activeSeriesIds !== [], fn (Builder $pages) => $pages->whereNotIn('series_id', $activeSeriesIds))
                    ->where(function (Builder $pages): void {
                        $pages->where('is_indexable', true)
                            ->orWhere('indexability_status', '!=', PublicSeriesIndexabilityStatus::NotInActivePublication->value);
                    })
                    ->update([
                        'is_indexable' => false,
                        'indexability_status' => PublicSeriesIndexabilityStatus::NotInActivePublication->value,
                        'generated_at' => now(),
                        'updated_at' => now(),
                    ]);
                $datasetCounts['stale'] += $stale;
                $totals['stale'] += $stale;
            }

            $datasets[] = [
                'dataset' => $pointer->dataset->code,
                'active_import' => $import->public_id,
                ...$datasetCounts,
            ];

            if ($limit !== null && $totals['series_scanned'] >= $limit) {
                break;
            }
        }

        return new PublicSeriesRefreshResult(
            $totals['series_scanned'],
            $totals['indexable'],
            $totals['non_indexable'],
            $totals['created'],
            $totals['updated'],
            $totals['unchanged'],
            $totals['failed'],
            $totals['stale'],
            $dryRun,
            $datasets,
        );
    }

    private function persist(PublicStatisticalSeriesSnapshot $snapshot): string
    {
        return DB::transaction(function () use ($snapshot): string {
            $page = StatisticalPublicSeriesPage::query()->firstOrNew(['series_id' => $snapshot->seriesId]);
            $created = ! $page->exists;
            $page->fill($snapshot->attributes());

            if (! $created && ! $page->isDirty()) {
                return 'unchanged';
            }

            $page->generated_at = $snapshot->generatedAt;
            $page->save();

            return $created ? 'created' : 'updated';
        });
    }
}
