<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;

trait BuildsPublicSeoFixture
{
    use BuildsPublicSnapshotFixture;

    /** @return array<string, mixed> */
    private function publicSeoFixture(
        string $itemCode = '31.02.10.140',
        string $itemName = 'Наборы кухонной мебели',
    ): array {
        $values = $this->monthlySnapshotValues('2025-01', '2025-12');
        $values['2025-02'] = '163.4146829442';
        $fixture = $this->publicSnapshotFixture($values, $itemCode, $itemName);

        app(RefreshPublicStatisticalSeriesPages::class)->execute($fixture['dataset']->code);
        $fixture['page'] = StatisticalPublicSeriesPage::query()
            ->where('series_id', $fixture['series']->id)
            ->firstOrFail();

        return $fixture;
    }

    private function addPublicSeoPage(array $fixture, int $ordinal): StatisticalPublicSeriesPage
    {
        $code = sprintf('31.99.%02d.%03d', intdiv($ordinal, 100), $ordinal % 1000);
        $item = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => $code,
            'name' => 'Тестовый товар '.$ordinal,
            'normalized_name' => 'тестовый товар '.$ordinal,
        ]);
        $series = $this->addSeriesForItem($fixture, $item);

        return StatisticalPublicSeriesPage::query()->create([
            'dataset_id' => $fixture['dataset']->id,
            'import_id' => $fixture['import']->id,
            'series_id' => $series->id,
            'classifier_item_id' => $item->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'slug' => str_replace('.', '-', $code),
            'is_indexable' => true,
            'indexability_status' => PublicSeriesIndexabilityStatus::Indexable,
            'period_from' => '2025-01-01',
            'period_to' => '2025-12-01',
            'observations_count' => 12,
            'factors_count' => 11,
            'coefficient_raw' => '1.00000000000000000000',
            'coefficient' => '1.000000000000',
            'change_percent_raw' => '0.00000000000000000000',
            'change_percent' => '0.00',
            'min_index_value' => '100.0000000000',
            'min_index_period' => '2025-01-01',
            'max_index_value' => '100.0000000000',
            'max_index_period' => '2025-12-01',
            'generated_at' => '2026-08-12 12:00:00',
            'source_published_at' => '2026-07-01 10:00:00',
        ]);
    }
}
