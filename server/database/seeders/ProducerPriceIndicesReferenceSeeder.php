<?php

namespace Database\Seeders;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Database\Seeder;

class ProducerPriceIndicesReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $dataset = StatisticalDataset::query()
            ->where('code', 'producer_price_indices_by_product')
            ->sole();
        $normalizer = app(StatisticalNameNormalizer::class);

        StatisticalIndicator::query()->firstOrCreate(
            [
                'dataset_id' => $dataset->id,
                'code' => 'producer_price_index',
            ],
            [
                'name' => 'Индекс цен производителей',
                'data_kind' => 'index',
            ]
        );

        StatisticalTerritory::query()->firstOrCreate(
            ['code' => 'RU'],
            [
                'name' => 'Российская Федерация',
                'normalized_name' => $normalizer->normalize('Российская Федерация'),
                'type' => 'country',
            ]
        );
    }
}
