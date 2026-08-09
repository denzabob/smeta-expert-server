<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Database\Seeders\ProducerPriceIndicesDatasetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProducerPriceIndicesDatasetSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeder_is_idempotent_and_does_not_overwrite_admin_settings(): void
    {
        $this->seed(ProducerPriceIndicesDatasetSeeder::class);

        $dataset = StatisticalDataset::query()
            ->where('code', 'producer_price_indices_by_product')
            ->sole();

        $this->assertSame('Росстат', $dataset->provider_name);
        $this->assertFalse($dataset->automatic_check_enabled);
        $this->assertSame(0, $dataset->sources()->count());

        $dataset->update([
            'name' => 'Изменено администратором',
            'automatic_check_enabled' => true,
            'check_schedule' => 'daily',
        ]);

        $this->seed(ProducerPriceIndicesDatasetSeeder::class);

        $dataset->refresh();
        $this->assertSame(1, StatisticalDataset::query()->where('code', $dataset->code)->count());
        $this->assertSame('Изменено администратором', $dataset->name);
        $this->assertTrue($dataset->automatic_check_enabled);
        $this->assertSame('daily', $dataset->check_schedule);
        $this->assertSame(0, $dataset->sources()->count());
    }
}
