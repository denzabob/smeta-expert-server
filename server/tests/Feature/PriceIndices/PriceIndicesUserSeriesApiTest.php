<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\Feature\PriceIndices\Support\BuildsUserCalculationFixture;
use Tests\TestCase;

class PriceIndicesUserSeriesApiTest extends TestCase
{
    use BuildsUserCalculationFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
    }

    public function test_user_routes_preserve_current_access_policy_and_future_non_admin_mode(): void
    {
        $this->getJson('/api/indices/series')->assertUnauthorized();

        $this->actingAsPriceIndicesRole('user');
        $this->getJson('/api/indices/series')->assertForbidden();

        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAsPriceIndicesRole($role);
            $this->getJson('/api/indices/series')->assertOk();
        }

        Config::set('price_indices.admin_only', false);
        $this->actingAsPriceIndicesRole('user');
        $this->getJson('/api/indices/series')->assertOk();
    }

    public function test_search_reuses_exact_prefix_name_and_ag_semantics_on_active_import(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '101.0000000000',
        ]);
        $this->addSeries($fixture, '31.02.10.150', 'Шкафы деревянные');
        $ag = $this->addSeries($fixture, '05.10.10.101.АГ', 'Локальный товар Росстата');

        $base = '/api/indices/series?dataset_public_id='.$fixture['dataset']->public_id.'&';
        $this->getJson($base.http_build_query(['item_code' => '31.02.10.140']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.period.from', '2024-01')
            ->assertJsonPath('data.0.period.to', '2024-02')
            ->assertJsonPath('data.0.period.observations_count', 2)
            ->assertJsonMissingPath('data.0.id');

        $this->getJson($base.http_build_query(['item_code_prefix' => '31.02']))
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($base.http_build_query(['item_name' => " кухонной\u{00A0}мебели "]))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.http_build_query(['item_code' => '05.10.10.101.аг']))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $ag->public_id)
            ->assertJsonPath('data.0.classifier_item.item_code', '05.10.10.101.АГ')
            ->assertJsonPath('data.0.classifier_item.provider_code_kind', 'rosstat_local_ag');
        $this->getJson('/api/indices/series/'.$ag->public_id)
            ->assertOk()
            ->assertJsonPath('data.classifier_item.item_code', '05.10.10.101.АГ');
    }

    public function test_detail_returns_active_context_but_historical_series_never_leaks(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $active = $this->calculationFixture(['2024-01' => '100.0000000000']);

        $this->getJson('/api/indices/series/'.$active['series']->public_id)
            ->assertOk()
            ->assertJsonPath('data.public_id', $active['series']->public_id)
            ->assertJsonPath('data.active_import.public_id', $active['import']->public_id)
            ->assertJsonPath('data.period.from', '2024-01')
            ->assertJsonMissingPath('data.active_import.id');

        $historicalItem = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'item_code' => '99.99.99.999',
            'name' => 'Только история',
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize('Только история'),
        ]);
        $historicalSeries = StatisticalSeries::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'indicator_id' => $active['indicator']->id,
            'classifier_item_id' => $historicalItem->id,
            'territory_id' => $active['territory']->id,
        ]);
        $historicalImport = StatisticalImport::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'source_file_id' => $active['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Superseded,
        ]);
        StatisticalObservation::factory()->create([
            'import_id' => $historicalImport->id,
            'series_id' => $historicalSeries->id,
            'source_file_id' => $active['sourceFile']->id,
            'period_start' => '2024-01-01',
        ]);

        $this->getJson('/api/indices/series?dataset_public_id='.$active['dataset']->public_id.'&item_code=99.99.99.999')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/indices/series/'.$historicalSeries->public_id)
            ->assertNotFound()->assertJsonPath('code', 'series_not_available');
    }

    public function test_dataset_without_active_publication_has_empty_search_and_hidden_detail(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture(['2024-01' => '100.0000000000'], active: false);

        $this->getJson('/api/indices/series?dataset_public_id='.$fixture['dataset']->public_id)
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/indices/series/'.$fixture['series']->public_id)
            ->assertNotFound()->assertJsonPath('code', 'series_not_available');
    }

    private function addSeries(array $fixture, string $code, string $name): StatisticalSeries
    {
        $item = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => $code,
            'name' => $name,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($name),
            'metadata_json' => str_ends_with($code, '.АГ') ? ['provider_code_kind' => 'rosstat_local_ag'] : null,
        ]);
        $series = StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $item->id,
            'territory_id' => $fixture['territory']->id,
        ]);
        StatisticalObservation::factory()->create([
            'import_id' => $fixture['import']->id,
            'series_id' => $series->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'period_start' => '2024-01-01',
        ]);

        return $series;
    }
}
