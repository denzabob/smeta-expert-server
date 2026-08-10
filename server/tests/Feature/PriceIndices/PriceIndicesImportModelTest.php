<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\ResolveClassifierItem;
use App\Domain\PriceIndices\Application\Services\ResolveStatisticalSeries;
use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportIssue;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Database\Seeders\ProducerPriceIndicesDatasetSeeder;
use Database\Seeders\ProducerPriceIndicesReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceIndicesImportModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_import_model_entities_use_unique_public_ids_for_routes(): void
    {
        $import = StatisticalImport::factory()->create(['status' => StatisticalImportStatus::Published]);
        $indicator = StatisticalIndicator::factory()->create();
        $classifier = StatisticalClassifierItem::factory()->create();
        $territory = StatisticalTerritory::factory()->create();
        $issue = StatisticalImportIssue::factory()->create(['import_id' => $import->id]);
        $series = StatisticalSeries::factory()->create();
        $observation = StatisticalObservation::factory()->create();
        $pointer = StatisticalDatasetActiveImport::query()->create([
            'dataset_id' => $import->dataset_id,
            'import_id' => $import->id,
            'published_at' => now(),
        ]);

        foreach ([$indicator, $classifier, $territory, $import, $issue, $series, $observation, $pointer] as $model) {
            $this->assertTrue(Str::isUuid($model->public_id));
            $this->assertSame('public_id', $model->getRouteKeyName());
        }

        $duplicate = $indicator->replicate();
        $duplicate->public_id = $indicator->public_id;
        $duplicate->code = 'another-code';
        $this->assertQueryConstraint(fn () => $duplicate->save());
    }

    public function test_dimension_and_import_identity_constraints_are_enforced(): void
    {
        $indicator = StatisticalIndicator::factory()->create();
        $this->assertQueryConstraint(fn () => StatisticalIndicator::factory()->create([
            'dataset_id' => $indicator->dataset_id,
            'code' => $indicator->code,
        ]));

        $classifier = StatisticalClassifierItem::factory()->create();
        $this->assertQueryConstraint(fn () => StatisticalClassifierItem::factory()->create([
            'dataset_id' => $classifier->dataset_id,
            'classifier_code' => $classifier->classifier_code,
            'item_code' => $classifier->item_code,
        ]));

        $territory = StatisticalTerritory::factory()->create();
        $this->assertQueryConstraint(fn () => StatisticalTerritory::factory()->create([
            'code' => $territory->code,
        ]));

        $import = StatisticalImport::factory()->create();
        $this->assertQueryConstraint(fn () => StatisticalImport::factory()->create([
            'dataset_id' => $import->dataset_id,
            'source_file_id' => $import->source_file_id,
            'importer_code' => $import->importer_code,
            'importer_version' => $import->importer_version,
            'attempt_no' => $import->attempt_no,
        ]));

        StatisticalImport::factory()->create(['successful_dedupe_key' => null]);
        $dedupe = hash('sha256', 'successful-import');
        StatisticalImport::factory()->create(['successful_dedupe_key' => $dedupe]);
        $this->assertQueryConstraint(fn () => StatisticalImport::factory()->create([
            'successful_dedupe_key' => $dedupe,
        ]));

        $series = StatisticalSeries::factory()->create();
        $this->assertQueryConstraint(fn () => StatisticalSeries::factory()->create([
            'dataset_id' => $series->dataset_id,
            'indicator_id' => $series->indicator_id,
            'classifier_item_id' => $series->classifier_item_id,
            'territory_id' => $series->territory_id,
            'frequency' => $series->frequency,
            'comparison_basis' => $series->comparison_basis,
            'unit' => $series->unit,
        ]));
    }

    public function test_observations_preserve_decimal_missing_period_and_provenance_contracts(): void
    {
        $observation = StatisticalObservation::factory()->create([
            'value' => '99.9900000000',
            'period_start' => '2026-06-01',
            'sheet_name' => '24',
            'source_row' => 20920,
            'source_column' => 'H',
            'source_cell_address' => 'H20920',
            'source_value_raw' => '97,511)',
            'footnote_marker' => '1)',
        ]);

        $this->assertSame('99.9900000000', $observation->value);
        $this->assertSame('2026-06-01', $observation->period_start->toDateString());
        $this->assertSame(['24', 20920, 'H', 'H20920', '97,511)', '1)'], [
            $observation->sheet_name,
            $observation->source_row,
            $observation->source_column,
            $observation->source_cell_address,
            $observation->source_value_raw,
            $observation->footnote_marker,
        ]);

        $missing = StatisticalObservation::factory()->create([
            'period_start' => '2026-07-01',
            'value' => null,
            'missing_reason' => StatisticalObservationMissingReason::Ellipsis,
        ]);
        $this->assertNull($missing->value);
        $this->assertSame(StatisticalObservationMissingReason::Ellipsis, $missing->missing_reason);

        $this->assertQueryConstraint(fn () => StatisticalObservation::factory()->create([
            'value' => null,
            'missing_reason' => null,
        ]));
        $this->assertQueryConstraint(fn () => StatisticalObservation::factory()->create([
            'value' => '1.0000000000',
            'missing_reason' => StatisticalObservationMissingReason::Dash,
        ]));
        $this->assertQueryConstraint(fn () => StatisticalObservation::factory()->create([
            'import_id' => $observation->import_id,
            'series_id' => $observation->series_id,
            'source_file_id' => $observation->source_file_id,
            'period_start' => $observation->period_start,
        ]));
    }

    public function test_resolvers_reuse_semantic_identity_and_report_classifier_name_changes(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $normalizer = app(StatisticalNameNormalizer::class);
        $classifierResolver = app(ResolveClassifierItem::class);
        $first = $classifierResolver->execute($dataset, 'okpd2_based', '10.11', "  Мясо\u{00A0}  свежее ");
        $same = $classifierResolver->execute($dataset, 'okpd2_based', '10.11', 'мясо свежее');
        $changed = $classifierResolver->execute($dataset, 'okpd2_based', '10.11', 'Мясо охлаждённое');

        $this->assertSame('мясо свежее', $normalizer->normalize(" МЯСО\u{00A0} свежее "));
        $this->assertSame($first->item->id, $same->item->id);
        $this->assertFalse($same->nameChanged);
        $this->assertSame($first->item->id, $changed->item->id);
        $this->assertTrue($changed->nameChanged);
        $this->assertSame('Мясо   свежее', $first->item->name);

        $indicator = StatisticalIndicator::factory()->create(['dataset_id' => $dataset->id]);
        $territory = StatisticalTerritory::factory()->create();
        $seriesResolver = app(ResolveStatisticalSeries::class);
        $series = $seriesResolver->execute($dataset, $indicator, $first->item, $territory, 'monthly', 'previous_month', 'percent');
        $sameSeries = $seriesResolver->execute($dataset, $indicator, $first->item, $territory, 'monthly', 'previous_month', 'percent');
        $otherClassifier = StatisticalClassifierItem::factory()->create(['dataset_id' => $dataset->id]);
        $otherTerritory = StatisticalTerritory::factory()->create();
        $classifierSeries = $seriesResolver->execute($dataset, $indicator, $otherClassifier, $territory, 'monthly', 'previous_month', 'percent');
        $territorySeries = $seriesResolver->execute($dataset, $indicator, $first->item, $otherTerritory, 'monthly', 'previous_month', 'percent');
        $otherBasis = $seriesResolver->execute($dataset, $indicator, $first->item, $territory, 'monthly', 'previous_year', 'percent');
        $otherUnit = $seriesResolver->execute($dataset, $indicator, $first->item, $territory, 'monthly', 'previous_month', 'basis_points');

        $this->assertSame($series->id, $sameSeries->id);
        $this->assertNotSame($series->id, $classifierSeries->id);
        $this->assertNotSame($series->id, $territorySeries->id);
        $this->assertNotSame($series->id, $otherBasis->id);
        $this->assertNotSame($series->id, $otherUnit->id);

        $foreignDataset = StatisticalDataset::factory()->create();
        $foreignIndicator = StatisticalIndicator::factory()->create(['dataset_id' => $foreignDataset->id]);
        $this->expectException(PriceIndicesInvariantViolation::class);
        $seriesResolver->execute($dataset, $foreignIndicator, $first->item, $territory, 'monthly', 'previous_month', 'percent');
    }

    public function test_observations_are_immutable_after_a_successful_import(): void
    {
        $import = StatisticalImport::factory()->create([
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $observation = StatisticalObservation::factory()->create([
            'import_id' => $import->id,
            'source_file_id' => $import->source_file_id,
        ]);

        try {
            $observation->update(['source_value_raw' => 'changed']);
            $this->fail('A statistical observation was unexpectedly updated.');
        } catch (PriceIndicesInvariantViolation) {
            $this->addToAssertionCount(1);
        }

        try {
            $observation->delete();
            $this->fail('A statistical observation from a successful import was unexpectedly deleted.');
        } catch (PriceIndicesInvariantViolation) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(StatisticalObservation::query()->whereKey($observation->id)->exists());
    }

    public function test_reference_seeder_is_idempotent_and_does_not_create_import_data(): void
    {
        $this->seed(ProducerPriceIndicesDatasetSeeder::class);
        $this->seed(ProducerPriceIndicesReferenceSeeder::class);
        $this->seed(ProducerPriceIndicesReferenceSeeder::class);

        $dataset = StatisticalDataset::query()->where('code', 'producer_price_indices_by_product')->sole();
        $this->assertSame(1, $dataset->indicators()->where('code', 'producer_price_index')->count());
        $this->assertSame(1, StatisticalTerritory::query()->where('code', 'RU')->count());
        $this->assertSame(0, $dataset->classifierItems()->count());
        $this->assertSame(0, $dataset->series()->count());
        $this->assertSame(0, $dataset->imports()->count());
    }

    private function assertQueryConstraint(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
