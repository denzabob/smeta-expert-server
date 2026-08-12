<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSnapshotFixture;
use Tests\TestCase;

class RefreshPublicSeriesPagesCommandTest extends TestCase
{
    use BuildsPublicSnapshotFixture;
    use DatabaseTransactions;

    public function test_dry_run_writes_nothing_and_normal_runs_are_idempotent(): void
    {
        $fixture = $this->publicSnapshotFixture();

        $this->artisan('price-indices:refresh-public-pages', [
            '--dataset' => $fixture['dataset']->code,
            '--dry-run' => true,
        ])->expectsOutput('Series scanned: 1')
            ->expectsOutput('Indexable: 1')
            ->expectsOutput('Created: 0')
            ->expectsOutput('Dry run: yes')
            ->assertSuccessful();
        $this->assertSame(0, StatisticalPublicSeriesPage::query()->count());

        $this->artisan('price-indices:refresh-public-pages', [
            '--dataset' => $fixture['dataset']->code,
        ])->expectsOutput('Created: 1')->assertSuccessful();
        $publicId = StatisticalPublicSeriesPage::query()->sole()->public_id;

        $this->artisan('price-indices:refresh-public-pages', [
            '--dataset' => $fixture['dataset']->code,
        ])->expectsOutput('Created: 0')
            ->expectsOutput('Unchanged: 1')
            ->assertSuccessful();

        $this->assertSame(1, StatisticalPublicSeriesPage::query()->count());
        $this->assertSame($publicId, StatisticalPublicSeriesPage::query()->sole()->public_id);
    }

    public function test_series_selector_and_invalid_limit_are_controlled(): void
    {
        $fixture = $this->publicSnapshotFixture(itemCode: '05.10.10.101.АГ');

        $this->artisan('price-indices:refresh-public-pages', [
            '--dataset' => $fixture['dataset']->public_id,
            '--series' => '05.10.10.101.АГ',
            '--dry-run' => true,
        ])->expectsOutput('Series scanned: 1')->assertSuccessful();

        $this->artisan('price-indices:refresh-public-pages', ['--limit' => '0'])
            ->expectsOutput('--limit must be a positive integer.')
            ->assertFailed();
    }
}
