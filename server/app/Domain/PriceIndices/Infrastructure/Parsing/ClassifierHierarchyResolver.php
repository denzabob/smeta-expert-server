<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class ClassifierHierarchyResolver
{
    /**
     * @param  list<RawClassifierNode>  $rawNodes
     * @return array{nodes: list<ParsedClassifierNode>, skipped_immediate_parents: int}
     */
    public function resolve(array $rawNodes): array
    {
        $byCode = [];

        foreach ($rawNodes as $node) {
            $byCode[$node->code] = $node;
        }

        $resolved = [];
        $skippedImmediateParents = 0;

        foreach ($rawNodes as $index => $node) {
            $parentCode = null;

            if ($node->semanticLevel === ClassifierSemanticLevel::ClassLevel) {
                $section = $byCode[$node->sectionCode] ?? null;

                if (! $section instanceof RawClassifierNode
                    || $section->semanticLevel !== ClassifierSemanticLevel::Section
                ) {
                    throw $this->orphanFailure();
                }

                $parentCode = $section->code;
            } elseif ($node->semanticLevel !== ClassifierSemanticLevel::Section) {
                $candidates = $this->ancestorCandidates($node);
                $parentCode = $this->firstExisting($candidates, $byCode, $node->sectionCode);

                if ($parentCode === null) {
                    throw $this->orphanFailure();
                }

                if ($candidates !== [] && $parentCode !== $candidates[0]) {
                    $skippedImmediateParents++;
                }
            }

            $resolved[] = new ParsedClassifierNode(
                code: $node->code,
                name: $node->name,
                normalizedName: $node->normalizedName,
                semanticLevel: $node->semanticLevel,
                formalDepth: $node->formalDepth,
                sourceOrder: $index + 1,
                parentCode: $parentCode,
                notes: $node->notes,
                metadata: [
                    'source_part' => $node->sourcePart,
                    'source_row' => $node->sourceRow,
                ],
            );
        }

        return [
            'nodes' => $resolved,
            'skipped_immediate_parents' => $skippedImmediateParents,
        ];
    }

    /** @return list<string> */
    private function ancestorCandidates(RawClassifierNode $node): array
    {
        $code = $node->code;

        return match ($node->semanticLevel) {
            ClassifierSemanticLevel::Subclass => [substr($code, 0, 2)],
            ClassifierSemanticLevel::Group => [substr($code, 0, 4), substr($code, 0, 2)],
            ClassifierSemanticLevel::Subgroup => [substr($code, 0, 5), substr($code, 0, 4), substr($code, 0, 2)],
            ClassifierSemanticLevel::Type => [substr($code, 0, 7), substr($code, 0, 5), substr($code, 0, 4), substr($code, 0, 2)],
            ClassifierSemanticLevel::Category => [substr($code, 0, 8), substr($code, 0, 7), substr($code, 0, 5), substr($code, 0, 4), substr($code, 0, 2)],
            ClassifierSemanticLevel::Subcategory => [
                substr($code, 0, -1).'0',
                substr($code, 0, 8),
                substr($code, 0, 7),
                substr($code, 0, 5),
                substr($code, 0, 4),
                substr($code, 0, 2),
            ],
            default => [],
        };
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string, RawClassifierNode>  $byCode
     */
    private function firstExisting(array $candidates, array $byCode, string $sectionCode): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($byCode[$candidate]) && $byCode[$candidate]->sectionCode === $sectionCode) {
                return $candidate;
            }
        }

        return null;
    }

    private function orphanFailure(): ClassifierParserException
    {
        return ClassifierParserException::fatal(
            'impossible_classifier_hierarchy',
            'A classifier node has no valid existing ancestor.'
        );
    }
}
