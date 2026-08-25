<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Infrastructure\Parsing\ClassifierHierarchyValidator;
use Tests\Feature\PriceIndices\Support\ClassifierParserTestCase;

class Okpd2RosstatDocxParserTest extends ClassifierParserTestCase
{
    public function test_observed_two_part_wordprocessingml_pattern_produces_an_immutable_snapshot_dto(): void
    {
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact());
        $snapshot = $this->parser()->parse($source, $this->syntheticExpectedProfile());
        $nodes = collect($snapshot->nodes)->keyBy('code');

        $this->assertSame('okpd2_rosstat_docx', $snapshot->parserCode);
        $this->assertSame(1, $snapshot->parserVersion);
        $this->assertSame(21, $snapshot->sectionsCount);
        $this->assertSame(7, $snapshot->digitalNodesCount);
        $this->assertSame(28, $snapshot->totalNodesCount);
        $this->assertCount(28, $snapshot->nodes);
        $this->assertSame(range('A', 'U'), collect($snapshot->nodes)->where('semanticLevel', ClassifierSemanticLevel::Section)->pluck('code')->all());

        $target = $nodes->get('31.02.10.140');
        $this->assertSame('31.02.10.140', $target->code);
        $this->assertSame('Наборы кухонной мебели', $target->name);
        $this->assertSame('наборы кухонной мебели', $target->normalizedName);
        $this->assertSame(ClassifierSemanticLevel::Category, $target->semanticLevel);
        $this->assertSame(6, $target->formalDepth);
        $this->assertSame('31.02.10', $target->parentCode);
        $this->assertSame('TIZ_OKPD2_1.docx', $target->metadata['source_part']);
        $this->assertIsInt($target->metadata['source_row']);
        $this->assertSame('Этот раздел также включает: строительные работы', $nodes->get('F')->notes);
        $this->assertSame('C', $nodes->get('31')->parentCode);
        $this->assertSame('31', $nodes->get('31.0')->parentCode);
        $this->assertSame('31.0', $nodes->get('31.02')->parentCode);
        $this->assertSame('31.02', $nodes->get('31.02.1')->parentCode);
        $this->assertSame('31.02.1', $nodes->get('31.02.10')->parentCode);
        $this->assertSame('31.02.10.140', $nodes->get('31.02.10.141')->parentCode);
        $this->assertSame([], $snapshot->warnings);
        $this->assertSame([], $snapshot->validationSummary->fatalErrors);
        $this->assertSame(0, $snapshot->validationSummary->metrics['skipped_immediate_parents']);
        $this->assertSame(1, $snapshot->validationSummary->metrics['notes_count']);
        $this->assertGreaterThan(0, $snapshot->validationSummary->metrics['nodes_per_second']);
    }

    public function test_source_order_crosses_the_part_boundary_without_sorting_by_code(): void
    {
        $snapshot = $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact()),
            $this->syntheticExpectedProfile(),
        );
        $codes = array_column($snapshot->nodes, 'code');

        $this->assertSame('D', $codes[10]);
        $this->assertSame('E', $codes[11]);
        $this->assertSame(range(1, 28), array_column($snapshot->nodes, 'sourceOrder'));
    }

    public function test_missing_immediate_category_uses_the_nearest_existing_type_without_a_synthetic_node(): void
    {
        $partOne = array_values(array_filter(
            $this->defaultOkpd2PartOneRows(),
            fn (array $row): bool => trim($row['left']) !== '31.02.10.140',
        ));
        $snapshot = $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($partOne)),
            $this->syntheticExpectedProfile(
                digitalNodes: 6,
                totalNodes: 27,
                levelCounts: [
                    'section' => 21,
                    'class' => 1,
                    'subclass' => 1,
                    'group' => 1,
                    'subgroup' => 1,
                    'type' => 1,
                    'category' => 0,
                    'subcategory' => 1,
                ],
            ),
        );
        $nodes = collect($snapshot->nodes)->keyBy('code');

        $this->assertSame('31.02.10', $nodes->get('31.02.10.141')->parentCode);
        $this->assertFalse($nodes->has('31.02.10.140'));
        $this->assertSame(1, $snapshot->validationSummary->metrics['skipped_immediate_parents']);
    }

    public function test_impossible_orphan_is_fatal(): void
    {
        $partOne = [
            ['left' => 'РАЗДЕЛ A', 'right' => 'A'],
            ['left' => 'РАЗДЕЛ B', 'right' => 'B'],
            ['left' => 'РАЗДЕЛ C', 'right' => 'C'],
            ['left' => '31.02.10.141', 'right' => 'Orphan'],
            ['left' => 'РАЗДЕЛ D', 'right' => 'D'],
        ];
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($partOne));

        $this->assertParserError(
            'impossible_classifier_hierarchy',
            fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
        );
    }

    public function test_identical_duplicate_is_deduplicated_with_warning(): void
    {
        $partOne = $this->defaultOkpd2PartOneRows();
        array_splice($partOne, 10, 0, [[
            'left' => '31.02.10.141',
            'right' => 'Наборы кухонной мебели деревянные',
        ]]);
        $snapshot = $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($partOne)),
            $this->syntheticExpectedProfile(),
        );

        $this->assertCount(28, $snapshot->nodes);
        $this->assertSame('identical_classifier_duplicate', $snapshot->warnings[0]->code);
        $this->assertSame(1, $snapshot->validationSummary->metrics['identical_duplicate_rows']);
    }

    public function test_conflicting_duplicate_is_fatal(): void
    {
        $partOne = $this->defaultOkpd2PartOneRows();
        array_splice($partOne, 10, 0, [[
            'left' => '31.02.10.141',
            'right' => 'Conflicting name',
        ]]);
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($partOne));

        $this->assertParserError(
            'conflicting_classifier_duplicate',
            fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
        );
    }

    public function test_unknown_code_mask_and_incompatible_part_sections_are_fatal(): void
    {
        $unknown = $this->defaultOkpd2PartOneRows();
        $unknown[3]['left'] = '31.020';
        $this->assertParserError(
            'unknown_classifier_code_mask',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($unknown)),
                $this->syntheticExpectedProfile(),
            ),
        );

        $missingSection = array_values(array_filter(
            $this->defaultOkpd2PartTwoRows(),
            fn (array $row): bool => $row['left'] !== 'РАЗДЕЛ U',
        ));
        $this->assertParserError(
            'incompatible_docx_part_sections',
            fn () => $this->parser()->parse(
                $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(partTwoRows: $missingSection)),
                $this->syntheticExpectedProfile(),
            ),
        );
    }

    public function test_explicit_profile_detects_catastrophic_loss_and_exact_count_drift(): void
    {
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact());
        $profile = $this->syntheticExpectedProfile();
        $tooLargeMinimum = new \App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile(
            requiredSections: $profile->requiredSections,
            minimumDigitalNodes: 500,
        );

        $this->assertParserError(
            'catastrophic_classifier_node_loss',
            fn () => $this->parser()->parse($source, $tooLargeMinimum),
        );

        $wrongExactCount = new \App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile(
            requiredSections: $profile->requiredSections,
            minimumDigitalNodes: 1,
            exactDigitalNodesCount: 8,
        );
        $this->assertParserError(
            'unexpected_classifier_digital_node_count',
            fn () => $this->parser()->parse($source, $wrongExactCount),
        );
    }

    public function test_cycle_validator_rejects_cycles(): void
    {
        $nodes = [
            new ParsedClassifierNode('A', 'A', 'a', ClassifierSemanticLevel::Section, 0, 1, '31'),
            new ParsedClassifierNode('31', '31', '31', ClassifierSemanticLevel::ClassLevel, 1, 2, 'A'),
        ];

        $this->assertParserError(
            'classifier_hierarchy_cycle',
            fn () => app(ClassifierHierarchyValidator::class)->validate($nodes),
        );
    }

    public function test_overlapping_code_block_across_parts_is_fatal(): void
    {
        $partTwo = $this->defaultOkpd2PartTwoRows();
        array_splice($partTwo, 1, 0, [[
            'left' => '31',
            'right' => 'Мебель',
        ]]);
        $source = $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact(partTwoRows: $partTwo));

        $this->assertParserError(
            'conflicting_classifier_duplicate',
            fn () => $this->parser()->parse($source, $this->syntheticExpectedProfile()),
        );
    }

    public function test_parser_does_not_persist_classifier_lifecycle_rows(): void
    {
        $before = [
            'imports' => \DB::table('statistical_classifier_imports')->count(),
            'versions' => \DB::table('statistical_classifier_versions')->count(),
            'nodes' => \DB::table('statistical_classifier_nodes')->count(),
            'active' => \DB::table('statistical_classifier_active_versions')->count(),
        ];
        $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact()),
            $this->syntheticExpectedProfile(),
        );

        $this->assertSame($before, [
            'imports' => \DB::table('statistical_classifier_imports')->count(),
            'versions' => \DB::table('statistical_classifier_versions')->count(),
            'nodes' => \DB::table('statistical_classifier_nodes')->count(),
            'active' => \DB::table('statistical_classifier_active_versions')->count(),
        ]);
    }

    public function test_large_programmatic_fixture_reports_bounded_streaming_metrics(): void
    {
        $partOne = [
            ['left' => 'РАЗДЕЛ A', 'right' => 'A'],
            ['left' => 'РАЗДЕЛ B', 'right' => 'B'],
            ['left' => 'РАЗДЕЛ C', 'right' => 'C'],
            ['left' => '31', 'right' => 'Class 31'],
        ];

        for ($subclass = 0; $subclass <= 9; $subclass++) {
            $partOne[] = ['left' => "31.{$subclass}", 'right' => "Subclass {$subclass}"];

            for ($group = 0; $group <= 9; $group++) {
                $groupCode = "31.{$subclass}{$group}";
                $partOne[] = ['left' => $groupCode, 'right' => "Group {$subclass}{$group}"];

                for ($subgroup = 0; $subgroup <= 9; $subgroup++) {
                    $partOne[] = [
                        'left' => "{$groupCode}.{$subgroup}",
                        'right' => "Subgroup {$subclass}{$group}{$subgroup}",
                    ];

                    for ($type = 0; $type <= 9; $type++) {
                        $partOne[] = [
                            'left' => "{$groupCode}.{$subgroup}{$type}",
                            'right' => "Type {$subclass}{$group}{$subgroup}{$type}",
                        ];
                    }
                }
            }
        }

        $partOne[] = ['left' => 'РАЗДЕЛ D', 'right' => 'D'];
        $profile = $this->syntheticExpectedProfile(
            digitalNodes: 11_111,
            totalNodes: 11_132,
            levelCounts: [
                'section' => 21,
                'class' => 1,
                'subclass' => 10,
                'group' => 100,
                'subgroup' => 1_000,
                'type' => 10_000,
                'category' => 0,
                'subcategory' => 0,
            ],
        );
        $snapshot = $this->parser()->parse(
            $this->storeSyntheticArtifact($this->makeSyntheticOkpd2Artifact($partOne)),
            $profile,
        );
        $metrics = $snapshot->validationSummary->metrics;

        fwrite(STDERR, "\nOKPD2 largest synthetic parser metrics: ".json_encode([
            'nodes' => $snapshot->totalNodesCount,
            'elapsed_parse_ms' => $metrics['elapsed_parse_ms'],
            'peak_memory_bytes' => $metrics['peak_memory_bytes'],
            'peak_memory_delta_bytes' => $metrics['peak_memory_delta_bytes'],
            'nodes_per_second' => $metrics['nodes_per_second'],
        ])."\n");

        $this->assertSame(11_132, $snapshot->totalNodesCount);
        $this->assertGreaterThan(0, $metrics['elapsed_parse_ms']);
        $this->assertGreaterThan(0, $metrics['peak_memory_bytes']);
        $this->assertGreaterThan(0, $metrics['nodes_per_second']);
    }
}
