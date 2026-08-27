<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;

trait BuildsConsumerPublicFixture
{
    use BuildsPublicSnapshotFixture;

    /** @return array<string, mixed> */
    private function consumerPublicFamilyFixture(): array
    {
        $values = $this->monthlySnapshotValues('1991-01', '2026-07');
        $values['2026-05'] = '101.0000000000';
        $values['2026-06'] = '102.0000000000';
        $values['2026-07'] = '99.0000000000';
        $fixture = $this->publicSnapshotFixture(
            $values,
            'all_items_and_services',
            'Товары и услуги',
        );
        $fixture['dataset']->update([
            'code' => ParsedConsumerPriceIndexSnapshot::DATASET_CODE,
            'name' => 'Индексы потребительских цен по Российской Федерации, месяцы',
            'classifier_code' => ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
        ]);
        $fixture['sourceFile']->update([
            'source_url' => 'https://rosstat.gov.ru/statistics/price',
            'original_filename' => 'ipc_mes_07-2026.xlsx',
        ]);
        $fixture['import']->update([
            'importer_code' => ParsedConsumerPriceIndexSnapshot::DATASET_CODE,
            'importer_version' => '1.0.0',
            'metadata_json' => [
                'source_notes' => [
                    [
                        'code' => 'territorial_coverage_2026',
                        'text' => '*)Без учета статистической информации по Донецкой Народной Республике, Луганской Народной Республике, Запорожской и Херсонской областям.',
                        'sheet' => '01',
                        'cell' => 'A22',
                    ],
                    [
                        'code' => 'january_1998_denomination',
                        'text' => 'Обращаем Ваше внимание, что в январе 1998 г. была проведена деноминация, в результате которой произошло уменьшение масштаба цен в 1000 раз.',
                        'sheet' => '01',
                        'cell' => 'A20',
                    ],
                ],
            ],
        ]);
        $fixture['indicator']->update([
            'code' => 'consumer_price_index',
            'name' => 'Индекс потребительских цен',
        ]);
        $fixture['item']->update([
            'classifier_code' => ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
            'metadata_json' => ['identity_scope' => 'dataset_local', 'official_provider_code' => false],
        ]);

        $items = ['all_items_and_services' => $fixture['item']];
        foreach ([
            'food_products' => 'Продовольственные товары',
            'non_food_products' => 'Непродовольственные товары',
            'services' => 'Услуги',
        ] as $itemCode => $itemName) {
            $item = StatisticalClassifierItem::factory()->create([
                'dataset_id' => $fixture['dataset']->id,
                'classifier_code' => ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
                'item_code' => $itemCode,
                'name' => $itemName,
                'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($itemName),
                'metadata_json' => ['identity_scope' => 'dataset_local', 'official_provider_code' => false],
            ]);
            $series = $this->addSeriesForItem($fixture, $item);
            $this->addObservations($fixture['import'], $series, $values);
            $items[$itemCode] = $item;
        }

        app(RefreshPublicStatisticalSeriesPages::class)->execute($fixture['dataset']->code);
        $pages = StatisticalPublicSeriesPage::query()
            ->where('dataset_id', $fixture['dataset']->id)
            ->with('classifierItem:id,item_code,name')
            ->get()
            ->keyBy(fn (StatisticalPublicSeriesPage $page): string => (string) $page->classifierItem->item_code);

        return [...$fixture, 'items' => $items, 'pages' => $pages, 'values' => $values];
    }
}
