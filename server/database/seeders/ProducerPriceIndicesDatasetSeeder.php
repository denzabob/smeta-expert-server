<?php

namespace Database\Seeders;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Database\Seeder;

class ProducerPriceIndicesDatasetSeeder extends Seeder
{
    public function run(): void
    {
        StatisticalDataset::query()->firstOrCreate(
            ['code' => 'producer_price_indices_by_product'],
            [
                'name' => 'Индексы цен производителей по товарам и товарным группам',
                'provider_code' => 'rosstat',
                'provider_name' => 'Росстат',
                'data_kind' => 'price_index',
                'frequency' => 'monthly',
                'classifier_code' => 'okpd2_based',
                'territory_scope' => 'russian_federation',
                'is_enabled' => true,
                'automatic_check_enabled' => false,
            ]
        );
    }
}
