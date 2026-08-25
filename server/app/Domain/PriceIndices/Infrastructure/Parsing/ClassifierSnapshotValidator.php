<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class ClassifierSnapshotValidator
{
    public function __construct(private readonly ClassifierHierarchyValidator $hierarchy) {}

    /**
     * @param  list<ParsedClassifierNode>  $nodes
     * @return array{sections_count: int, digital_nodes_count: int, total_nodes_count: int, level_counts: array<string, int>}
     */
    public function validate(array $nodes, ClassifierExpectedProfile $profile): array
    {
        $this->hierarchy->validate($nodes);
        $levelCounts = array_fill_keys(array_map(
            fn (ClassifierSemanticLevel $level): string => $level->value,
            ClassifierSemanticLevel::cases(),
        ), 0);
        $sections = [];

        foreach ($nodes as $node) {
            $levelCounts[$node->semanticLevel->value]++;

            if ($node->semanticLevel === ClassifierSemanticLevel::Section) {
                $sections[] = $node->code;
            }
        }

        $sectionsCount = $levelCounts[ClassifierSemanticLevel::Section->value];
        $totalNodesCount = count($nodes);
        $digitalNodesCount = $totalNodesCount - $sectionsCount;

        if ($sections !== $profile->requiredSections) {
            throw $this->failure('missing_classifier_sections', 'The classifier section sequence is incomplete or invalid.');
        }

        if ($digitalNodesCount < $profile->minimumDigitalNodes) {
            throw $this->failure('catastrophic_classifier_node_loss', 'The classifier contains anomalously few digital nodes.');
        }

        $this->assertExact($profile->exactSectionsCount, $sectionsCount, 'unexpected_classifier_section_count');
        $this->assertExact($profile->exactDigitalNodesCount, $digitalNodesCount, 'unexpected_classifier_digital_node_count');
        $this->assertExact($profile->exactTotalNodesCount, $totalNodesCount, 'unexpected_classifier_total_node_count');

        if ($profile->exactLevelCounts !== null) {
            foreach ($profile->exactLevelCounts as $level => $expected) {
                if (($levelCounts[$level] ?? null) !== $expected) {
                    throw $this->failure(
                        'unexpected_classifier_level_count',
                        'The classifier semantic-level profile does not match the expected profile.'
                    );
                }
            }
        }

        return [
            'sections_count' => $sectionsCount,
            'digital_nodes_count' => $digitalNodesCount,
            'total_nodes_count' => $totalNodesCount,
            'level_counts' => $levelCounts,
        ];
    }

    private function assertExact(?int $expected, int $actual, string $code): void
    {
        if ($expected !== null && $expected !== $actual) {
            throw $this->failure($code, 'The classifier does not match its explicit expected validation profile.');
        }
    }

    private function failure(string $code, string $message): ClassifierParserException
    {
        return ClassifierParserException::fatal($code, $message);
    }
}
