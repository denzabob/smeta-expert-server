<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\SearchPublicIndexPages;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClassifierPublicSearchPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_combined_search_is_measured_on_production_like_classifier_without_new_name_index(): void
    {
        $version = $this->activeVersion();
        $this->insertNodes($version, 20_982);
        $captured = [];
        DB::listen(function (QueryExecuted $query) use (&$captured): void {
            if (str_contains($query->sql, 'public_index_search_results')) {
                $captured[] = $query;
            }
        });

        $metrics = [];
        foreach ([
            'exact_code' => '31.02.10.140',
            'code_prefix' => '31.02.10',
            'name_prefix' => 'наборы кухонной',
            'contains' => 'кухонной мебели',
            'combined_terms' => 'кухонная мебель',
        ] as $mode => $query) {
            $captured = [];
            $startedAt = hrtime(true);
            $results = app(SearchPublicIndexPages::class)->execute($query);
            $elapsedMs = round((hrtime(true) - $startedAt) / 1_000_000, 3);
            $select = $captured[array_key_last($captured)] ?? null;

            $this->assertNotNull($select, "Search SQL was not captured for {$mode}.");
            $this->assertGreaterThan(0, $results->total(), "Expected a control result for {$mode}.");
            $this->assertSame(1, $results->total(), "Expected only the control result for {$mode}.");
            $this->assertLessThan(1500, $elapsedMs, "{$mode} search exceeded the 1500 ms gate.");

            $metrics[$mode] = [
                'elapsed_ms' => $elapsedMs,
                'results' => $results->total(),
                'explain' => array_map(
                    fn (object $row): array => [
                        'select_type' => $row->select_type ?? null,
                        'table' => $row->table ?? null,
                        'type' => $row->type ?? null,
                        'possible_keys' => $row->possible_keys ?? null,
                        'key' => $row->key ?? null,
                        'rows' => isset($row->rows) ? (int) $row->rows : null,
                        'extra' => $row->Extra ?? null,
                    ],
                    DB::select('EXPLAIN '.$select->sql, $select->bindings),
                ),
            ];
        }

        fwrite(STDERR, 'OKPD2 public search benchmark: '.json_encode([
            'nodes' => 20_982,
            'normalized_name_index_added' => false,
            'metrics' => $metrics,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    private function activeVersion(): StatisticalClassifierVersion
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'performance-test',
        ]);

        return $version;
    }

    private function insertNodes(StatisticalClassifierVersion $version, int $count): void
    {
        $now = now();
        $rows = [];

        for ($ordinal = 1; $ordinal <= $count; $ordinal++) {
            $isControl = $ordinal === 1;
            $name = $isControl ? 'Наборы кухонной мебели' : 'Тестовая продукция '.$ordinal;
            $rows[] = [
                'public_id' => (string) Str::uuid(),
                'classifier_version_id' => $version->id,
                'code' => $isControl ? '31.02.10.140' : sprintf('99.%05d', $ordinal),
                'name' => $name,
                'normalized_name' => mb_strtolower($name, 'UTF-8'),
                'semantic_level' => 'category',
                'formal_depth' => 1,
                'parent_node_id' => null,
                'source_order' => $ordinal,
                'notes_text' => null,
                'metadata_json' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                DB::table('statistical_classifier_nodes')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('statistical_classifier_nodes')->insert($rows);
        }
    }
}
