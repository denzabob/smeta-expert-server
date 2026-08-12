<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\PriceIndices\Support\BuildsPublicSnapshotFixture;
use Tests\TestCase;

class PublicSeriesPageModelTest extends TestCase
{
    use BuildsPublicSnapshotFixture;
    use DatabaseTransactions;

    public function test_schema_model_decimal_and_relations_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('statistical_public_series_pages', [
            'public_id', 'dataset_id', 'import_id', 'series_id', 'classifier_item_id',
            'source_file_id', 'slug', 'is_indexable', 'indexability_status',
            'coefficient_raw', 'coefficient', 'change_percent_raw', 'change_percent',
        ]));
        $fixture = $this->publicSnapshotFixture();
        $page = StatisticalPublicSeriesPage::query()->create([
            'dataset_id' => $fixture['dataset']->id,
            'import_id' => $fixture['import']->id,
            'series_id' => $fixture['series']->id,
            'classifier_item_id' => $fixture['item']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'slug' => '31-02-10-140',
            'is_indexable' => true,
            'indexability_status' => PublicSeriesIndexabilityStatus::Indexable,
            'coefficient_raw' => '1.23456789012345678901',
            'coefficient' => '1.234567890123',
            'change_percent_raw' => '23.45678901234567890100',
            'change_percent' => '23.46',
            'generated_at' => now(),
        ]);

        $this->assertNotEmpty($page->public_id);
        $this->assertSame('public_id', $page->getRouteKeyName());
        $this->assertSame('1.23456789012345678901', $page->fresh()->coefficient_raw);
        $this->assertSame($fixture['series']->id, $page->series->id);
        $this->assertSame($fixture['import']->id, $page->import->id);
        $this->assertSame($fixture['sourceFile']->id, $page->sourceFile->id);
        $this->assertSame($fixture['dataset']->id, $page->dataset->id);
        $this->assertSame($fixture['item']->id, $page->classifierItem->id);
    }

    public function test_series_is_unique(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $attributes = [
            'dataset_id' => $fixture['dataset']->id,
            'import_id' => $fixture['import']->id,
            'series_id' => $fixture['series']->id,
            'classifier_item_id' => $fixture['item']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'slug' => '31-02-10-140',
            'indexability_status' => PublicSeriesIndexabilityStatus::Indexable,
            'generated_at' => now(),
        ];
        StatisticalPublicSeriesPage::query()->create($attributes);

        try {
            StatisticalPublicSeriesPage::query()->create($attributes);
            $this->fail('Duplicate series_id must be rejected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_slug_is_unique_across_different_series(): void
    {
        $first = $this->publicSnapshotFixture();
        $second = $this->publicSnapshotFixture(itemCode: '31.02.10.150');
        foreach ([$first, $second] as $fixture) {
            try {
                StatisticalPublicSeriesPage::query()->create([
                    'dataset_id' => $fixture['dataset']->id,
                    'import_id' => $fixture['import']->id,
                    'series_id' => $fixture['series']->id,
                    'classifier_item_id' => $fixture['item']->id,
                    'source_file_id' => $fixture['sourceFile']->id,
                    'slug' => 'same-slug',
                    'indexability_status' => PublicSeriesIndexabilityStatus::Indexable,
                    'generated_at' => now(),
                ]);
            } catch (QueryException) {
                $this->assertSame($second['series']->id, $fixture['series']->id);

                return;
            }
        }

        $this->fail('Duplicate slug must be rejected.');
    }
}
