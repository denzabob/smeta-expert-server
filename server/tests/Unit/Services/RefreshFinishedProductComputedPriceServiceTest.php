<?php

namespace Tests\Unit\Services;

use App\Models\FinishedProductAggregationProfile;
use App\Models\FinishedProductComputedPrice;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;
use App\Models\User;
use App\Services\RefreshFinishedProductComputedPriceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RefreshFinishedProductComputedPriceServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_refresh_persists_projection_row(): void
    {
        $user = User::factory()->create();
        $specification = FinishedProductSpecification::create([
            'user_id' => $user->id,
            'product_type' => FinishedProductSpecification::TYPE_FACADE,
            'name' => 'Projection facade',
            'article' => 'FPSPEC:projection',
            'is_active' => true,
            'facade_class' => 'standard',
            'base_type' => 'mdf',
            'thickness_mm' => 16,
            'covering' => 'pvc',
        ]);

        FinishedProductAggregationProfile::create([
            'finished_product_specification_id' => $specification->id,
            'method' => 'median',
            'include_only_active' => true,
            'exclude_stale' => true,
        ]);

        FinishedProductPriceSource::create([
            'finished_product_specification_id' => $specification->id,
            'source_kind' => FinishedProductPriceSource::KIND_MANUAL_ENTRY,
            'source_price' => 3200,
            'source_unit' => 'м²',
            'price_per_m2_normalized' => 3200,
            'status' => FinishedProductPriceSource::STATUS_ACTIVE,
        ]);

        $projection = app(RefreshFinishedProductComputedPriceService::class)->refresh($specification);

        $this->assertInstanceOf(FinishedProductComputedPrice::class, $projection);
        $this->assertSame($specification->id, $projection->finished_product_specification_id);
        $this->assertSame('median', $projection->method);
        $this->assertSame('3200.0000', (string) $projection->computed_price_per_m2);
        $this->assertSame(1, $projection->source_count);
        $this->assertNotNull($projection->computed_at);
    }
}
