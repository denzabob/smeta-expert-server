<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSnapshotFixture;
use Tests\TestCase;

class RefreshPublicSeriesPagesTest extends TestCase
{
    use BuildsPublicSnapshotFixture;
    use DatabaseTransactions;

    public function test_refresh_is_idempotent_and_preserves_public_identity(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $refresh = app(RefreshPublicStatisticalSeriesPages::class);

        $first = $refresh->execute($fixture['dataset']->code);
        $page = StatisticalPublicSeriesPage::query()->where('series_id', $fixture['series']->id)->sole();
        $publicId = $page->public_id;
        $second = $refresh->execute($fixture['dataset']->public_id);

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame(1, StatisticalPublicSeriesPage::query()->count());
        $this->assertSame($publicId, $page->fresh()->public_id);
    }

    public function test_publication_switch_updates_snapshot_and_marks_absent_series_stale(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $refresh = app(RefreshPublicStatisticalSeriesPages::class);
        $refresh->execute($fixture['dataset']->code);

        $secondImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Published,
            'published_at' => '2026-08-01 10:00:00',
        ]);
        $newItem = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => '31.02.10.150',
        ]);
        $newSeries = $this->addSeriesForItem($fixture, $newItem);
        $this->addObservations($secondImport, $newSeries, $this->monthlySnapshotValues('2025-01', '2025-12', '101.0000000000'));
        $fixture['import']->update(['status' => StatisticalImportStatus::Superseded]);
        StatisticalDatasetActiveImport::query()->where('dataset_id', $fixture['dataset']->id)->update([
            'import_id' => $secondImport->id,
            'published_at' => '2026-08-01 10:00:00',
        ]);

        $result = $refresh->execute($fixture['dataset']->code);
        $stale = StatisticalPublicSeriesPage::query()->where('series_id', $fixture['series']->id)->sole();
        $current = StatisticalPublicSeriesPage::query()->where('series_id', $newSeries->id)->sole();

        $this->assertSame(1, $result->stale);
        $this->assertFalse($stale->is_indexable);
        $this->assertSame(PublicSeriesIndexabilityStatus::NotInActivePublication, $stale->indexability_status);
        $this->assertSame($fixture['import']->id, $stale->import_id);
        $this->assertSame($secondImport->id, $current->import_id);
    }

    public function test_slug_collision_is_non_indexable_and_never_overwrites_another_series(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $territory = StatisticalTerritory::factory()->create();
        $other = StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $fixture['item']->id,
            'territory_id' => $territory->id,
        ]);
        $this->addObservations($fixture['import'], $other, $this->monthlySnapshotValues('2025-01', '2025-12'));

        $result = app(RefreshPublicStatisticalSeriesPages::class)->execute($fixture['dataset']->code);

        $this->assertSame(2, $result->failed);
        $this->assertSame(2, StatisticalPublicSeriesPage::query()->count());
        $this->assertSame(2, StatisticalPublicSeriesPage::query()->whereNull('slug')->count());
        $this->assertSame(2, StatisticalPublicSeriesPage::query()
            ->where('indexability_status', PublicSeriesIndexabilityStatus::SlugCollision->value)->count());
    }

    public function test_controlled_failure_of_one_series_does_not_abort_other_snapshots(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $invalidItem = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => '31.02.10.151',
            'name' => '',
            'normalized_name' => '',
        ]);
        $invalidSeries = $this->addSeriesForItem($fixture, $invalidItem);
        $this->addObservations($fixture['import'], $invalidSeries, $this->monthlySnapshotValues('2025-01', '2025-12'));

        $result = app(RefreshPublicStatisticalSeriesPages::class)->execute($fixture['dataset']->code);

        $this->assertSame(2, $result->seriesScanned);
        $this->assertSame(1, $result->indexable);
        $this->assertSame(1, $result->nonIndexable);
        $this->assertSame(2, $result->created);
        $this->assertSame(PublicSeriesIndexabilityStatus::InvalidMetadata, StatisticalPublicSeriesPage::query()
            ->where('series_id', $invalidSeries->id)->sole()->indexability_status);
    }

    public function test_one_and_one_hundred_series_have_bounded_queries_memory_and_time(): void
    {
        $fixture = $this->publicSnapshotFixture();
        for ($number = 1; $number < 100; $number++) {
            $item = StatisticalClassifierItem::factory()->create([
                'dataset_id' => $fixture['dataset']->id,
                'item_code' => sprintf('31.99.%02d.%03d', intdiv($number, 10), $number),
            ]);
            $series = $this->addSeriesForItem($fixture, $item);
            $this->addObservations($fixture['import'], $series, $this->monthlySnapshotValues('2025-01', '2025-12'));
        }

        $refresh = app(RefreshPublicStatisticalSeriesPages::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $oneStarted = hrtime(true);
        $refresh->execute($fixture['dataset']->code, limit: 1, dryRun: true);
        $oneMs = intdiv(hrtime(true) - $oneStarted, 1_000_000);
        $oneQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $memoryBefore = memory_get_usage(true);
        $hundredStarted = hrtime(true);
        $result = $refresh->execute($fixture['dataset']->code, dryRun: true);
        $hundredMs = intdiv(hrtime(true) - $hundredStarted, 1_000_000);
        $hundredQueries = count(DB::getQueryLog());
        $memoryDelta = max(0, memory_get_usage(true) - $memoryBefore);
        DB::disableQueryLog();
        $extrapolated1327Ms = intdiv($hundredMs * 1327, 100);

        fwrite(STDOUT, "\nPublic snapshot performance: ".json_encode([
            'one_series_ms' => $oneMs,
            'one_series_queries' => $oneQueries,
            'one_hundred_series_ms' => $hundredMs,
            'one_hundred_series_queries' => $hundredQueries,
            'one_hundred_memory_delta_bytes' => $memoryDelta,
            'extrapolated_1327_ms' => $extrapolated1327Ms,
        ], JSON_THROW_ON_ERROR)."\n");

        $this->assertSame(100, $result->seriesScanned);
        $this->assertLessThanOrEqual(15, $oneQueries);
        $this->assertLessThanOrEqual(320, $hundredQueries);
        $this->assertLessThan(30_000, $hundredMs);
        $this->assertLessThan(64 * 1024 * 1024, $memoryDelta);
    }
}
