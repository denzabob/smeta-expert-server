<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\ResolvePublicClassifierContext;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDOException;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndexClassifierEnrichmentTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_confirmed_active_mapping_renders_persisted_lineage_bounded_children_and_real_destinations(): void
    {
        $fixture = $this->publicSeoFixture();
        [, $version, $lineage] = $this->controlHierarchy();
        $this->mapping($fixture['item'], $version, $lineage['31.02.10.140']);

        $children = [];
        foreach (range(1, 17) as $ordinal) {
            $children[$ordinal] = $this->node(
                $version,
                sprintf('31.02.10.140.%02d', $ordinal),
                'Дочерняя позиция '.$ordinal,
                $lineage['31.02.10.140'],
                100 + $ordinal,
            );
        }

        $linkedPage = $this->addPublicSeoPage(
            $fixture,
            1,
            $children[1]->code,
            $children[1]->name,
        );
        $this->mapping($linkedPage->classifierItem()->firstOrFail(), $version, $children[1]);

        $hiddenPage = $this->addPublicSeoPage(
            $fixture,
            2,
            $children[2]->code,
            $children[2]->name,
        );
        $hiddenPage->update(['is_indexable' => false]);
        $this->mapping($hiddenPage->classifierItem()->firstOrFail(), $version, $children[2]);

        $ambiguousPage = $this->addPublicSeoPage(
            $fixture,
            3,
            $children[3]->code,
            $children[3]->name,
        );
        $this->mapping(
            $ambiguousPage->classifierItem()->firstOrFail(),
            $version,
            $children[3],
            'ambiguous',
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('https://indices.test/'.$fixture['page']->slug);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()
            ->assertSee('<h2 id="official-classifier-title">Официальная классификация</h2>', false)
            ->assertSee('<dd>145/2026</dd>', false)
            ->assertSee('<dd>06.07.2026</dd>', false)
            ->assertSee('classifier-position--current', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee('<h3>Дочерние позиции</h3>', false)
            ->assertSee('Дочерняя позиция 15')
            ->assertDontSee('Дочерняя позиция 16')
            ->assertDontSee('Дочерняя позиция 17')
            ->assertSee('href="https://indices.test/'.$linkedPage->slug.'"', false)
            ->assertSee('href="https://indices.test/?q=31.02.10.140"', false)
            ->assertDontSee('href="https://indices.test/'.$hiddenPage->slug.'"', false)
            ->assertDontSee('href="https://indices.test/'.$ambiguousPage->slug.'"', false)
            ->assertDontSee('<h2>Связанные индексы</h2>', false)
            ->assertDontSee('/okpd2/', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'Есть данные Росстата'));
        $this->assertLineageOrder($html, [
            'C',
            '31',
            '31.0',
            '31.02',
            '31.02.1',
            '31.02.10',
            '31.02.10.140',
        ]);
        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_inactive_ambiguous_unmapped_local_and_missing_active_mapping_keep_legacy_ssr(): void
    {
        $fixture = $this->publicSeoFixture();
        $legacyRelated = $this->addPublicSeoPage($fixture, 1, '31.02.10.141', 'Шкафы кухонные');
        [$classifier, $activeVersion, $activeLineage] = $this->controlHierarchy();
        $inactiveVersion = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create([
            'version_label' => '144/2025',
            'effective_from' => '2025-01-01',
        ]);
        $inactiveNode = $this->node($inactiveVersion, '31.02.10.140', 'Старое имя');
        $this->mapping($fixture['item'], $inactiveVersion, $inactiveNode);

        $this->assertLegacyFallback($fixture['page'], $legacyRelated->slug);

        $activeMapping = $this->mapping(
            $fixture['item'],
            $activeVersion,
            $activeLineage['31.02.10.140'],
            'ambiguous',
        );
        $this->assertLegacyFallback($fixture['page'], $legacyRelated->slug);

        $activeMapping->update([
            'classifier_node_id' => null,
            'mapping_type' => 'unmapped',
        ]);
        $this->assertLegacyFallback($fixture['page'], $legacyRelated->slug);

        $activeMapping->update(['mapping_type' => 'local_rosstat']);
        $this->assertLegacyFallback($fixture['page'], $legacyRelated->slug);

        $activeMapping->update([
            'classifier_node_id' => $activeLineage['31.02.10.140']->id,
            'mapping_type' => 'exact',
        ]);
        StatisticalClassifierActiveVersion::query()->where('classifier_id', $classifier->id)->delete();
        $this->assertLegacyFallback($fixture['page'], $legacyRelated->slug);
    }

    public function test_lineage_follows_saved_nearest_parent_instead_of_deriving_parent_from_code(): void
    {
        $fixture = $this->publicSeoFixture('31.02.10.141', 'Шкафы кухонные');
        [, $version, $lineage] = $this->controlHierarchy(includeCurrent: false);
        $current = $this->node(
            $version,
            '31.02.10.141',
            'Шкафы кухонные',
            $lineage['31.02.1'],
            7,
        );
        $this->mapping($fixture['item'], $version, $current);

        $response = $this->get('https://indices.test/'.$fixture['page']->slug)->assertOk();
        $html = $response->getContent();

        $this->assertLineageOrder($html, ['C', '31', '31.0', '31.02', '31.02.1', '31.02.10.141']);
        $this->assertStringNotContainsString(
            '<span class="classifier-code">31.02.10</span>',
            $this->classifierSection($html),
        );
    }

    public function test_only_mysql_missing_table_error_falls_back_silently(): void
    {
        $page = new StatisticalPublicSeriesPage;
        $page->setAttribute('id', 1);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')
            ->once()
            ->andThrow($this->queryException('42S02', 1146));

        $this->assertNull((new ResolvePublicClassifierContext($database))->execute($page));
    }

    public function test_other_sql_errors_are_not_masked(): void
    {
        $page = new StatisticalPublicSeriesPage;
        $page->setAttribute('id', 1);
        $exception = $this->queryException('HY000', 1205);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('table')->once()->andThrow($exception);

        try {
            (new ResolvePublicClassifierContext($database))->execute($page);
            $this->fail('A non-schema SQL error was unexpectedly masked.');
        } catch (QueryException $actual) {
            $this->assertSame($exception, $actual);
        }
    }

    /**
     * @return array{StatisticalClassifier, StatisticalClassifierVersion, array<string, StatisticalClassifierNode>}
     */
    private function controlHierarchy(bool $includeCurrent = true): array
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create([
            'version_label' => '145/2026',
            'effective_from' => '2026-07-06',
        ]);
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'public classifier test',
        ]);

        $definitions = [
            ['C', 'Продукция обрабатывающих производств'],
            ['31', 'Мебель'],
            ['31.0', 'Мебель'],
            ['31.02', 'Мебель кухонная'],
            ['31.02.1', 'Производство кухонной мебели'],
            ['31.02.10', 'Мебель кухонная'],
        ];
        if ($includeCurrent) {
            $definitions[] = ['31.02.10.140', 'Наборы кухонной мебели'];
        }

        $nodes = [];
        $parent = null;
        foreach ($definitions as $index => [$code, $name]) {
            $parent = $this->node($version, $code, $name, $parent, $index + 1);
            $nodes[$code] = $parent;
        }

        return [$classifier, $version, $nodes];
    }

    private function node(
        StatisticalClassifierVersion $version,
        string $code,
        string $name,
        ?StatisticalClassifierNode $parent = null,
        ?int $sourceOrder = null,
    ): StatisticalClassifierNode {
        return StatisticalClassifierNode::factory()->for($version, 'version')->create([
            'code' => $code,
            'name' => $name,
            'normalized_name' => mb_strtolower($name, 'UTF-8'),
            'parent_node_id' => $parent?->id,
            'formal_depth' => $parent === null ? 1 : min(8, ((int) $parent->formal_depth) + 1),
            'source_order' => $sourceOrder,
        ]);
    }

    private function mapping(
        StatisticalClassifierItem $item,
        StatisticalClassifierVersion $version,
        ?StatisticalClassifierNode $node,
        string $mappingType = 'exact',
    ): StatisticalClassifierItemMapping {
        return StatisticalClassifierItemMapping::query()->create([
            'statistical_classifier_item_id' => $item->id,
            'classifier_version_id' => $version->id,
            'classifier_node_id' => $node?->id,
            'mapping_type' => $mappingType,
            'review_status' => 'confirmed',
            'method' => 'manual:public-enrichment-test',
            'confirmed_at' => now(),
        ]);
    }

    private function assertLegacyFallback(StatisticalPublicSeriesPage $page, string $relatedSlug): void
    {
        $this->get('https://indices.test/'.$page->slug)
            ->assertOk()
            ->assertDontSee('id="official-classifier-title"', false)
            ->assertSee('<h2>Связанные индексы</h2>', false)
            ->assertSee('href="https://indices.test/'.$relatedSlug.'"', false)
            ->assertSee('Помесячные индексы')
            ->assertSee('data-public-index-calculator', false);
    }

    /** @param list<string> $codes */
    private function assertLineageOrder(string $html, array $codes): void
    {
        $section = $this->classifierSection($html);
        $offset = 0;

        foreach ($codes as $code) {
            $needle = '<span class="classifier-code">'.$code.'</span>';
            $position = strpos($section, $needle, $offset);
            $this->assertNotFalse($position, "Classifier code {$code} was not found in persisted lineage order.");
            $offset = $position + strlen($needle);
        }
    }

    private function classifierSection(string $html): string
    {
        $start = strpos($html, '<section class="panel section classifier-context"');
        $this->assertNotFalse($start, 'Official classifier section was not rendered.');
        $end = strpos($html, '</section>', $start);
        $this->assertNotFalse($end, 'Official classifier section was not closed.');

        return substr($html, $start, $end - $start);
    }

    private function queryException(string $sqlState, int $driverCode): QueryException
    {
        $previous = new PDOException('Database test error.');
        $previous->errorInfo = [$sqlState, $driverCode, 'Database test error.'];

        return new QueryException('mysql', 'select 1', [], $previous);
    }
}
