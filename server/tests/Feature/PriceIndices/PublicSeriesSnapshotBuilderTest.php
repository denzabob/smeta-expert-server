<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\BuildPublicStatisticalSeriesSnapshot;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSnapshotFixture;
use Tests\TestCase;

class PublicSeriesSnapshotBuilderTest extends TestCase
{
    use BuildsPublicSnapshotFixture;
    use DatabaseTransactions;

    public function test_control_series_uses_full_period_and_excludes_base_month_from_factors(): void
    {
        $values = $this->monthlySnapshotValues('2021-01', '2026-06');
        $values['2026-06'] = '100.1900000000';
        $fixture = $this->publicSnapshotFixture($values, '31.02.10.140');

        $snapshot = app(BuildPublicStatisticalSeriesSnapshot::class)
            ->execute($fixture['import'], $fixture['series']);

        $this->assertSame(PublicSeriesIndexabilityStatus::Indexable, $snapshot->status);
        $this->assertSame('2021-01-01', $snapshot->periodFrom);
        $this->assertSame('2026-06-01', $snapshot->periodTo);
        $this->assertSame(66, $snapshot->observationsCount);
        $this->assertSame(65, $snapshot->factorsCount);
        $this->assertSame('1.00190000000000000000', $snapshot->coefficientRaw);
        $this->assertSame('1.001900000000', $snapshot->coefficient);
        $this->assertSame('0.19000000000000000000', $snapshot->changePercentRaw);
        $this->assertSame('0.19', $snapshot->changePercent);
        $this->assertSame('100.0000000000', $snapshot->minIndexValue);
        $this->assertSame('100.1900000000', $snapshot->maxIndexValue);
    }

    public function test_decrease_and_min_max_are_decimal_safe(): void
    {
        $values = $this->monthlySnapshotValues('2025-01', '2025-12');
        $values['2025-06'] = '90.0000000000';
        $values['2025-12'] = '95.0000000000';
        $fixture = $this->publicSnapshotFixture($values);

        $snapshot = app(BuildPublicStatisticalSeriesSnapshot::class)
            ->execute($fixture['import'], $fixture['series']);

        $this->assertSame('0.85500000000000000000', $snapshot->coefficientRaw);
        $this->assertSame('-14.50', $snapshot->changePercent);
        $this->assertSame('90.0000000000', $snapshot->minIndexValue);
        $this->assertSame('2025-06-01', $snapshot->minIndexPeriod);
        $this->assertSame('100.0000000000', $snapshot->maxIndexValue);
        $this->assertSame('2025-01-01', $snapshot->maxIndexPeriod);
    }

    public function test_all_eligibility_failures_are_materialized_as_controlled_statuses(): void
    {
        $builder = app(BuildPublicStatisticalSeriesSnapshot::class);

        $short = $this->publicSnapshotFixture($this->monthlySnapshotValues('2025-01', '2025-11'));
        $this->assertSame(
            PublicSeriesIndexabilityStatus::InsufficientHistory,
            $builder->execute($short['import'], $short['series'])->status
        );

        $gapValues = $this->monthlySnapshotValues('2024-12', '2025-12');
        unset($gapValues['2025-06']);
        $gap = $this->publicSnapshotFixture($gapValues, '31.02.10.141');
        $this->assertSame(
            PublicSeriesIndexabilityStatus::IncompleteChain,
            $builder->execute($gap['import'], $gap['series'])->status
        );

        $nullValues = $this->monthlySnapshotValues('2025-01', '2025-12');
        $nullValues['2025-12'] = null;
        $null = $this->publicSnapshotFixture($nullValues, '31.02.10.142');
        $this->assertSame(
            PublicSeriesIndexabilityStatus::IncompleteChain,
            $builder->execute($null['import'], $null['series'])->status
        );

        $unsupported = $this->publicSnapshotFixture(null, '31.02.10.143', comparisonBasis: 'year_over_year');
        $this->assertSame(
            PublicSeriesIndexabilityStatus::UnsupportedSeries,
            $builder->execute($unsupported['import'], $unsupported['series'])->status
        );

        $invalid = $this->publicSnapshotFixture(null, '31.02.10.144');
        $invalid['item']->update(['name' => '', 'normalized_name' => '']);
        $this->assertSame(
            PublicSeriesIndexabilityStatus::InvalidMetadata,
            $builder->execute($invalid['import'], $invalid['series']->refresh())->status
        );
    }

    public function test_future_annual_kipc_shape_is_default_denied(): void
    {
        $fixture = $this->publicSnapshotFixture(
            $this->monthlySnapshotValues('2025-01', '2025-12'),
            'food_products',
            'КИПЦ: продовольственные товары',
        );
        $fixture['dataset']->update(['code' => 'consumer_price_indices_rf_monthly']);
        $fixture['import']->unsetRelation('dataset');
        $fixture['series']->update(['frequency' => 'annual']);

        $snapshot = app(BuildPublicStatisticalSeriesSnapshot::class)
            ->execute($fixture['import'], $fixture['series']->refresh());

        $this->assertSame(PublicSeriesIndexabilityStatus::UnsupportedSeries, $snapshot->status);
    }
}
