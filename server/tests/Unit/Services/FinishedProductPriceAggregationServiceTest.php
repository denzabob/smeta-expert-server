<?php

namespace Tests\Unit\Services;

use App\Models\FinishedProductAggregationProfile;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;
use App\Models\User;
use App\Services\FinishedProductPriceAggregationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinishedProductPriceAggregationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mean_uses_multiple_active_sources(): void
    {
        $specification = $this->makeFacadeSpecification();
        FinishedProductAggregationProfile::create([
            'finished_product_specification_id' => $specification->id,
            'method' => 'mean',
            'include_only_active' => true,
            'exclude_stale' => true,
        ]);

        $this->makeSource($specification->id, 3000.00);
        $this->makeSource($specification->id, 3600.00);
        $this->makeSource($specification->id, 4200.00);

        $summary = app(FinishedProductPriceAggregationService::class)->aggregateForSpecification($specification);

        $this->assertSame(3600.0, $summary['computed_price_per_m2']);
        $this->assertSame('mean', $summary['method']);
        $this->assertSame(3, $summary['source_count']);
        $this->assertSame(3000.0, $summary['min_price']);
        $this->assertSame(4200.0, $summary['max_price']);
        $this->assertCount(3, $summary['used_source_ids']);
    }

    public function test_median_uses_multiple_active_sources(): void
    {
        $specification = $this->makeFacadeSpecification();
        FinishedProductAggregationProfile::create([
            'finished_product_specification_id' => $specification->id,
            'method' => 'median',
            'include_only_active' => true,
            'exclude_stale' => true,
        ]);

        $this->makeSource($specification->id, 3000.00);
        $this->makeSource($specification->id, 3500.00);
        $this->makeSource($specification->id, 5000.00);

        $summary = app(FinishedProductPriceAggregationService::class)->aggregateForSpecification($specification);

        $this->assertSame(3500.0, $summary['computed_price_per_m2']);
        $this->assertSame('median', $summary['method']);
    }

    public function test_inactive_and_invalid_sources_are_excluded(): void
    {
        $specification = $this->makeFacadeSpecification();
        FinishedProductAggregationProfile::create([
            'finished_product_specification_id' => $specification->id,
            'method' => 'mean',
            'include_only_active' => true,
            'exclude_stale' => true,
        ]);

        $active = $this->makeSource($specification->id, 3000.00);
        $this->makeSource($specification->id, 7000.00, FinishedProductPriceSource::STATUS_INACTIVE);
        $this->makeSource($specification->id, 9000.00, FinishedProductPriceSource::STATUS_INVALID);

        $summary = app(FinishedProductPriceAggregationService::class)->aggregateForSpecification($specification);

        $this->assertSame(3000.0, $summary['computed_price_per_m2']);
        $this->assertSame(1, $summary['source_count']);
        $this->assertSame([$active->id], $summary['used_source_ids']);
    }

    private function makeFacadeSpecification(): FinishedProductSpecification
    {
        $user = User::factory()->create();

        return FinishedProductSpecification::create([
            'user_id' => $user->id,
            'product_type' => FinishedProductSpecification::TYPE_FACADE,
            'name' => 'Тестовый фасад',
            'article' => 'FPSPEC:test-domain',
            'is_active' => true,
            'facade_class' => 'standard',
            'base_type' => 'mdf',
            'thickness_mm' => 16,
            'covering' => 'pvc',
        ]);
    }

    private function makeSource(
        int $specificationId,
        float $price,
        string $status = FinishedProductPriceSource::STATUS_ACTIVE
    ): FinishedProductPriceSource {
        return FinishedProductPriceSource::create([
            'finished_product_specification_id' => $specificationId,
            'source_kind' => FinishedProductPriceSource::KIND_MANUAL_ENTRY,
            'source_price' => $price,
            'source_unit' => 'м²',
            'price_per_m2_normalized' => $price,
            'status' => $status,
        ]);
    }
}
