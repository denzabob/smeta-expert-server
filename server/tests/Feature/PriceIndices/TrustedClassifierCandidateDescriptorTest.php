<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierValidationIssue;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Application\Services\ValidateClassifierCandidateSnapshot;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use Tests\TestCase;

class TrustedClassifierCandidateDescriptorTest extends TestCase
{
    public function test_known_candidate_has_exact_immutable_authoritative_profile(): void
    {
        $descriptor = app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');

        $this->assertSame('okpd2', $descriptor->classifierCode);
        $this->assertSame('145/2026', $descriptor->versionLabel);
        $this->assertSame('2026-07-06', $descriptor->effectiveFrom);
        $this->assertSame('71a35241c4318c1ffbe4b47feb5c47ce34bd1ea24a6b58661acd289ea91fc46', $descriptor->sourceSha256);
        $this->assertSame('okpd2_rosstat_docx', $descriptor->parserCode);
        $this->assertSame(1, $descriptor->parserVersion);
        $this->assertSame(21, $descriptor->expectedSectionsCount);
        $this->assertSame(20_961, $descriptor->expectedDigitalNodesCount);
        $this->assertSame(20_982, $descriptor->expectedTotalNodesCount);
        $this->assertSame(1_321, $descriptor->expectedNotesCount);
        $this->assertSame(0, $descriptor->expectedWarningsCount);
        $this->assertSame(8_401, $descriptor->expectedLevelCounts['category']);
        $this->assertSame('Наборы кухонной мебели', $descriptor->controlNodeName);
        $this->assertSame(ClassifierSemanticLevel::Category, $descriptor->controlNodeLevel);
        $this->assertSame('31.02.10', $descriptor->controlNodeParentCode);
        $this->assertSame([
            'C' => null,
            '31' => 'C',
            '31.0' => '31',
            '31.02' => '31.0',
            '31.02.1' => '31.02',
            '31.02.10' => '31.02.1',
        ], $descriptor->controlAncestorParents);
    }

    public function test_current_rar_candidate_has_the_measured_148_2026_profile(): void
    {
        $descriptor = app(TrustedClassifierCandidateRegistry::class)->get('okpd2_148_2026');

        $this->assertSame('148/2026', $descriptor->versionLabel);
        $this->assertSame('2026-08-26', $descriptor->effectiveFrom);
        $this->assertSame('586ea967cda82eaee7651e0f9f920bcb1cb39db93901932be2d130869b39952c', $descriptor->sourceSha256);
        $this->assertSame('rar', $descriptor->expectedArtifactType);
        $this->assertSame(['OKPD2 01-35.docx', 'OKPD2 36-99.docx'], $descriptor->expectedPartFilenames);
        $this->assertSame(21_595, $descriptor->expectedDigitalNodesCount);
        $this->assertSame(21_616, $descriptor->expectedTotalNodesCount);
        $this->assertSame(1_327, $descriptor->expectedNotesCount);
        $this->assertSame(8_587, $descriptor->expectedLevelCounts['category']);
        $this->assertSame(7_354, $descriptor->expectedLevelCounts['subcategory']);
    }

    public function test_unknown_candidate_is_a_controlled_failure(): void
    {
        try {
            app(TrustedClassifierCandidateRegistry::class)->get('latest');
            $this->fail('Unknown candidate was accepted.');
        } catch (ClassifierCandidateStagingException $exception) {
            $this->assertSame('classifier_candidate_not_supported', $exception->errorCode);
            $this->assertSame('descriptor', $exception->stage);
        }
    }

    public function test_candidate_fingerprint_is_deterministic_and_contains_no_timestamps(): void
    {
        $registry = app(TrustedClassifierCandidateRegistry::class);
        $first = $registry->get('okpd2_145_2026');
        $second = $registry->get('okpd2_145_2026');

        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->fingerprint());
        $this->assertArrayNotHasKey('created_at', $first->fingerprintPayload());
        $this->assertArrayNotHasKey('updated_at', $first->fingerprintPayload());

        $oldPayload = $first->fingerprintPayload();
        $oldPayload['expected_profile']['control_node']['ancestor_parents'] = [
            '31' => null,
            '31.0' => '31',
            '31.02' => '31.0',
            '31.02.1' => '31.02',
            '31.02.10' => '31.02.1',
        ];
        $oldFingerprint = hash('sha256', json_encode(
            $oldPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        $this->assertNotSame($oldFingerprint, $first->fingerprint());
    }

    public function test_exact_candidate_snapshot_passes(): void
    {
        $metrics = app(ValidateClassifierCandidateSnapshot::class)->validate(
            $this->descriptor(),
            $this->source(),
            $this->snapshot(),
        );

        $this->assertSame(20_982, $metrics['total_nodes_count']);
        $this->assertSame(1_321, $metrics['notes_count']);
        $this->assertSame(8_401, $metrics['level_counts']['category']);
    }

    public function test_exact_validation_rejects_section_total_notes_level_warnings_and_parser_drift(): void
    {
        $cases = [
            'sections' => [$this->snapshot(['sectionsCount' => 20]), 'candidate_sections_count_mismatch'],
            'digital' => [$this->snapshot(['digitalNodesCount' => 20_960]), 'candidate_digital_nodes_count_mismatch'],
            'total' => [$this->snapshot(['totalNodesCount' => 20_981]), 'candidate_total_nodes_count_mismatch'],
            'notes' => [$this->snapshot(['notesCount' => 1_320]), 'candidate_notes_count_mismatch'],
            'level' => [$this->snapshot(['categoryCount' => 8_400]), 'candidate_level_count_mismatch'],
            'warnings' => [$this->snapshot(['warningsCount' => 1]), 'candidate_warnings_count_mismatch'],
            'parser' => [$this->snapshot(['parserVersion' => 2]), 'candidate_parser_identity_mismatch'],
        ];

        foreach ($cases as $label => [$snapshot, $expectedCode]) {
            try {
                app(ValidateClassifierCandidateSnapshot::class)->validate(
                    $this->descriptor(),
                    $this->source(),
                    $snapshot,
                );
                $this->fail("Candidate validation case [{$label}] was accepted.");
            } catch (ClassifierCandidateStagingException $exception) {
                $this->assertSame($expectedCode, $exception->errorCode, $label);
                $this->assertSame('validating', $exception->stage, $label);
            }
        }
    }

    public function test_exact_validation_rejects_control_node_name_parent_and_ancestor_drift(): void
    {
        $cases = [
            [$this->snapshot(['controlName' => 'Неверное имя']), 'candidate_control_node_mismatch'],
            [$this->snapshot(['controlParent' => '31.02.1']), 'candidate_control_node_mismatch'],
            [$this->snapshot(['typeParent' => '31.0']), 'candidate_control_ancestor_mismatch'],
        ];

        foreach ($cases as [$snapshot, $expectedCode]) {
            try {
                app(ValidateClassifierCandidateSnapshot::class)->validate(
                    $this->descriptor(),
                    $this->source(),
                    $snapshot,
                );
                $this->fail('Invalid control-node profile was accepted.');
            } catch (ClassifierCandidateStagingException $exception) {
                $this->assertSame($expectedCode, $exception->errorCode);
            }
        }
    }

    private function descriptor(): \App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor
    {
        return app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');
    }

    private function source(): StatisticalClassifierSourceFile
    {
        return new StatisticalClassifierSourceFile([
            'sha256' => $this->descriptor()->sourceSha256,
        ]);
    }

    /** @param array<string, int|string> $overrides */
    private function snapshot(array $overrides = []): ParsedClassifierSnapshot
    {
        $descriptor = $this->descriptor();
        $warningsCount = (int) ($overrides['warningsCount'] ?? 0);
        $warnings = [];

        for ($index = 0; $index < $warningsCount; $index++) {
            $warnings[] = new ClassifierValidationIssue('test_warning', 'Bounded warning.');
        }

        $nodes = [
            $this->node('C', null, ClassifierSemanticLevel::Section),
            $this->node('31', 'C', ClassifierSemanticLevel::ClassLevel),
            $this->node('31.0', '31', ClassifierSemanticLevel::Subclass),
            $this->node('31.02', '31.0', ClassifierSemanticLevel::Group),
            $this->node('31.02.1', '31.02', ClassifierSemanticLevel::Subgroup),
            $this->node('31.02.10', (string) ($overrides['typeParent'] ?? '31.02.1'), ClassifierSemanticLevel::Type),
            new ParsedClassifierNode(
                code: '31.02.10.140',
                name: (string) ($overrides['controlName'] ?? $descriptor->controlNodeName),
                normalizedName: 'наборы кухонной мебели',
                semanticLevel: ClassifierSemanticLevel::Category,
                formalDepth: 6,
                sourceOrder: 6,
                parentCode: (string) ($overrides['controlParent'] ?? '31.02.10'),
            ),
        ];
        $levelCounts = $descriptor->expectedLevelCounts;
        $levelCounts['category'] = (int) ($overrides['categoryCount'] ?? $levelCounts['category']);
        $summary = new ClassifierValidationSummary(
            fatalErrors: [],
            warnings: $warnings,
            metrics: [
                'notes_count' => (int) ($overrides['notesCount'] ?? 1_321),
                'level_counts' => $levelCounts,
            ],
        );

        return new ParsedClassifierSnapshot(
            parserCode: (string) ($overrides['parserCode'] ?? 'okpd2_rosstat_docx'),
            parserVersion: (int) ($overrides['parserVersion'] ?? 1),
            sectionsCount: (int) ($overrides['sectionsCount'] ?? 21),
            digitalNodesCount: (int) ($overrides['digitalNodesCount'] ?? 20_961),
            totalNodesCount: (int) ($overrides['totalNodesCount'] ?? 20_982),
            nodes: $nodes,
            warnings: $warnings,
            validationSummary: $summary,
        );
    }

    private function node(
        string $code,
        ?string $parentCode,
        ClassifierSemanticLevel $level,
    ): ParsedClassifierNode {
        return new ParsedClassifierNode(
            code: $code,
            name: "Node {$code}",
            normalizedName: "node {$code}",
            semanticLevel: $level,
            formalDepth: 1,
            sourceOrder: 1,
            parentCode: $parentCode,
        );
    }
}
