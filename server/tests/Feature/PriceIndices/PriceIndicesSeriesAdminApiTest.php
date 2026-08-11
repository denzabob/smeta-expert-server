<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceIndicesSeriesAdminApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
    }

    public function test_exact_name_and_ag_search_return_import_scoped_series_contract(): void
    {
        $this->actingAsRole('admin');
        $fixture = $this->fixture();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '31.02.10.140']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $fixture['series']['kitchen']->public_id)
            ->assertJsonPath('data.0.classifier_item.public_id', $fixture['items']['kitchen']->public_id)
            ->assertJsonPath('data.0.classifier_item.classifier_code', 'okpd2_based')
            ->assertJsonPath('data.0.classifier_item.item_code', '31.02.10.140')
            ->assertJsonPath('data.0.classifier_item.provider_code_kind', 'numeric')
            ->assertJsonPath('data.0.indicator.code', 'producer_price_index')
            ->assertJsonPath('data.0.territory.code', 'RU')
            ->assertJsonPath('data.0.frequency', 'monthly')
            ->assertJsonPath('data.0.comparison_basis', 'previous_month')
            ->assertJsonPath('data.0.unit', 'percent')
            ->assertJsonPath('data.0.period.from', '2024-01-01')
            ->assertJsonPath('data.0.period.to', '2024-03-01')
            ->assertJsonPath('data.0.period.observations_count', 3)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.dataset_id')
            ->assertJsonMissingPath('data.0.classifier_item_id');
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->getJson($this->seriesUri($fixture['import'], ['item_name' => "  кухонной\u{00A0}мебели  "]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.classifier_item.item_code', '31.02.10.140');

        $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '05.10.10.101.аг']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.classifier_item.item_code', '05.10.10.101.АГ')
            ->assertJsonPath('data.0.classifier_item.provider_code_kind', 'rosstat_local_ag');

        $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '05.10.10.101']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.classifier_item.item_code', '05.10.10.101');
    }

    public function test_prefix_is_literal_does_not_require_trailing_dot_and_combines_with_name(): void
    {
        $this->actingAsRole('admin');
        $fixture = $this->fixture();

        $this->getJson($this->seriesUri($fixture['import'], ['item_code_prefix' => '31.02']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.classifier_item.item_code', '31.02.10.140')
            ->assertJsonPath('data.1.classifier_item.item_code', '31.02.10.150');

        $this->getJson($this->seriesUri($fixture['import'], [
            'item_code_prefix' => '31.02',
            'item_name' => 'мебел',
        ]))->assertOk()->assertJsonCount(1, 'data');

        foreach (['%', '_'] as $wildcard) {
            $this->getJson($this->seriesUri($fixture['import'], ['item_code_prefix' => $wildcard]))
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_multiple_series_for_one_item_are_separate_rows(): void
    {
        $this->actingAsRole('admin');
        $fixture = $this->fixture();
        $yearOverYear = StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $fixture['items']['kitchen']->id,
            'territory_id' => $fixture['territory']->id,
            'comparison_basis' => 'year_over_year',
        ]);
        $this->observation($fixture['import'], $yearOverYear, $fixture['source_file'], '2024-01-01', '110.0000000000');

        $response = $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '31.02.10.140']))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(
            [$fixture['series']['kitchen']->public_id, $yearOverYear->public_id],
            array_column($response->json('data'), 'public_id')
        );
        $this->assertEqualsCanonicalizing(
            ['previous_month', 'year_over_year'],
            array_column($response->json('data'), 'comparison_basis')
        );
    }

    public function test_period_summary_and_search_are_strictly_scoped_to_selected_import(): void
    {
        $this->actingAsRole('admin');
        $fixture = $this->fixture();
        $otherImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['source_file']->id,
            'attempt_no' => 2,
        ]);
        $this->observation($otherImport, $fixture['series']['kitchen'], $fixture['source_file'], '2030-01-01', '999.0000000000');

        $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '31.02.10.140']))
            ->assertOk()
            ->assertJsonPath('data.0.period.from', '2024-01-01')
            ->assertJsonPath('data.0.period.to', '2024-03-01')
            ->assertJsonPath('data.0.period.observations_count', 3);

        $onlyOtherImport = StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $fixture['items']['other']->id,
            'territory_id' => $fixture['territory']->id,
            'comparison_basis' => 'year_over_year',
        ]);
        $this->observation($otherImport, $onlyOtherImport, $fixture['source_file'], '2030-01-01', '100.0000000000');

        $this->getJson($this->seriesUri($fixture['import'], ['item_code' => '31.02.10.150']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $fixture['series']['other']->public_id)
            ->assertJsonMissing(['public_id' => $onlyOtherImport->public_id]);
    }

    public function test_observations_support_strict_series_scope_and_preserve_existing_contract(): void
    {
        $this->actingAsRole('admin');
        $fixture = $this->fixture();

        $this->getJson('/api/indices/admin/imports/'.$fixture['import']->public_id.'/observations?'.http_build_query([
            'series_public_id' => $fixture['series']['kitchen']->public_id,
            'period_from' => '2024-02-01',
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.series.public_id', $fixture['series']['kitchen']->public_id)
            ->assertJsonPath('data.0.value', '102.2500000000')
            ->assertJsonMissingPath('data.0.series.id');

        $otherImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['source_file']->id,
            'attempt_no' => 2,
        ]);
        $foreignSeries = StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $fixture['items']['kitchen']->id,
            'territory_id' => $fixture['territory']->id,
            'comparison_basis' => 'year_over_year',
        ]);
        $this->observation($otherImport, $foreignSeries, $fixture['source_file'], '2024-01-01', '120.0000000000');

        $this->getJson('/api/indices/admin/imports/'.$fixture['import']->public_id.'/observations?'.http_build_query([
            'series_public_id' => $foreignSeries->public_id,
        ]))->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/indices/admin/imports/'.$fixture['import']->public_id.'/observations')
            ->assertOk()
            ->assertJsonCount(6, 'data');
    }

    public function test_pagination_sort_validation_and_exact_role_access_are_preserved(): void
    {
        $fixture = $this->fixture();
        $uri = '/api/indices/admin/imports/'.$fixture['import']->public_id.'/series';

        $this->getJson($uri)->assertUnauthorized();
        foreach ([['user', 200], ['auditor', 201], ['user', 1]] as [$role, $id]) {
            $this->actingAsRole($role, $id);
            $this->getJson($uri)->assertForbidden();
        }
        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson($uri)->assertOk()->assertJsonPath('meta.per_page', 25);
        }

        $this->getJson($uri.'?per_page=50&sort=item_name&direction=desc')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50);
        $this->getJson($uri.'?per_page=51')->assertUnprocessable();
        $this->getJson($uri.'?sort=unsafe')->assertUnprocessable();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $dataset = StatisticalDataset::factory()->create();
        $sourceFile = StatisticalSourceFile::factory()->create(['dataset_id' => $dataset->id]);
        $import = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $sourceFile->id,
        ]);
        $indicator = StatisticalIndicator::factory()->create([
            'dataset_id' => $dataset->id,
            'code' => 'producer_price_index',
            'name' => 'Индекс цен производителей',
        ]);
        $territory = StatisticalTerritory::factory()->create([
            'code' => 'RU',
            'name' => 'Российская Федерация',
        ]);
        $normalizer = app(StatisticalNameNormalizer::class);
        $items = [
            'kitchen' => StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '31.02.10.140',
                'name' => 'Наборы кухонной мебели',
                'normalized_name' => $normalizer->normalize('Наборы кухонной мебели'),
            ]),
            'other' => StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '31.02.10.150',
                'name' => 'Шкафы деревянные',
                'normalized_name' => $normalizer->normalize('Шкафы деревянные'),
            ]),
            'base' => StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '05.10.10.101',
                'name' => 'Базовый товар',
                'normalized_name' => $normalizer->normalize('Базовый товар'),
            ]),
            'ag' => StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '05.10.10.101.АГ',
                'name' => 'Локальный товар',
                'normalized_name' => $normalizer->normalize('Локальный товар'),
                'metadata_json' => ['provider_code_kind' => 'rosstat_local_ag'],
            ]),
        ];
        $series = [];
        foreach ($items as $key => $item) {
            $series[$key] = StatisticalSeries::factory()->create([
                'dataset_id' => $dataset->id,
                'indicator_id' => $indicator->id,
                'classifier_item_id' => $item->id,
                'territory_id' => $territory->id,
            ]);
        }

        $this->observation($import, $series['kitchen'], $sourceFile, '2024-01-01', '109.5100000000');
        $this->observation($import, $series['kitchen'], $sourceFile, '2024-02-01', '102.2500000000');
        $this->observation($import, $series['kitchen'], $sourceFile, '2024-03-01', '106.8100000000');
        $this->observation($import, $series['other'], $sourceFile, '2024-01-01', '100.0000000000');
        $this->observation($import, $series['base'], $sourceFile, '2024-01-01', '101.0000000000');
        $this->observation($import, $series['ag'], $sourceFile, '2024-01-01', '103.0000000000');

        return compact('dataset', 'sourceFile', 'import', 'indicator', 'territory', 'items', 'series')
            + ['source_file' => $sourceFile];
    }

    private function observation(
        StatisticalImport $import,
        StatisticalSeries $series,
        StatisticalSourceFile $sourceFile,
        string $period,
        string $value,
    ): void {
        StatisticalObservation::factory()->create([
            'import_id' => $import->id,
            'series_id' => $series->id,
            'source_file_id' => $sourceFile->id,
            'period_start' => $period,
            'value' => $value,
            'missing_reason' => null,
        ]);
    }

    /** @param array<string, scalar> $query */
    private function seriesUri(StatisticalImport $import, array $query): string
    {
        return '/api/indices/admin/imports/'.$import->public_id.'/series?'.http_build_query($query);
    }

    private function actingAsRole(string $role, ?int $id = null): User
    {
        if ($id !== null) {
            $user = new User();
            $user->forceFill(['id' => $id, 'role' => $role]);
        } else {
            $user = User::factory()->create(['role' => $role]);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
