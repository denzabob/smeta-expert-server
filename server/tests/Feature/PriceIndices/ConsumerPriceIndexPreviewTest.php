<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\PreviewConsumerPriceIndexWorkbook;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPriceIndexWorkbook;
use Tests\TestCase;

class ConsumerPriceIndexPreviewTest extends TestCase
{
    use BuildsConsumerPriceIndexWorkbook;
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->cleanupConsumerPriceIndexWorkbooks();

        parent::tearDown();
    }

    public function test_preview_is_read_only_and_reports_supported_semantics(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook();
        $tables = [
            'statistical_datasets',
            'statistical_source_files',
            'statistical_imports',
            'statistical_classifier_items',
            'statistical_series',
            'statistical_observations',
        ];
        $before = $this->tableCounts($tables);

        $preview = app(PreviewConsumerPriceIndexWorkbook::class)->execute($path);

        $this->assertSame($before, $this->tableCounts($tables));
        $this->assertSame('consumer_price_indices_rf_monthly', $preview->datasetCandidate);
        $this->assertSame('rosstat_cpi_aggregate', $preview->classifier);
        $this->assertSame(4, $preview->series);
        $this->assertSame('1991-01-01', $preview->firstPeriod);
        $this->assertSame('1992-07-01', $preview->lastPeriod);
        $this->assertSame(76, $preview->totalObservations);
        $this->assertSame('RU', $preview->territory);
        $this->assertSame('monthly', $preview->frequency);
        $this->assertSame('previous_month', $preview->comparisonBasis);
        $this->assertSame('percent', $preview->unit);
        $this->assertSame('Тестовое обновление', $preview->sourceUpdatedLabel);
        $this->assertCount(2, $preview->sourceNotes);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
