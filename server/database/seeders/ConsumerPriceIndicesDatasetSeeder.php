<?php

namespace Database\Seeders;

use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Database\Seeder;

class ConsumerPriceIndicesDatasetSeeder extends Seeder
{
    public function run(): void
    {
        StatisticalDataset::query()->firstOrCreate(
            ['code' => ParsedConsumerPriceIndexSnapshot::DATASET_CODE],
            [
                'name' => 'Индексы потребительских цен по Российской Федерации, месяцы',
                'provider_code' => 'rosstat',
                'provider_name' => 'Росстат',
                'data_kind' => 'index',
                'frequency' => ParsedConsumerPriceIndexSnapshot::FREQUENCY,
                'classifier_code' => ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
                'territory_scope' => 'russian_federation',
                'is_enabled' => true,
                'automatic_check_enabled' => false,
            ]
        );
    }
}
