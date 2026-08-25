<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class ClassifierHierarchyValidator
{
    /** @param list<ParsedClassifierNode> $nodes */
    public function validate(array $nodes): void
    {
        $byCode = [];

        foreach ($nodes as $node) {
            if (isset($byCode[$node->code])) {
                throw $this->failure('duplicate_classifier_code', 'Classifier node codes must be unique.');
            }

            $byCode[$node->code] = $node;
        }

        foreach ($nodes as $node) {
            if ($node->parentCode === $node->code
                || ($node->parentCode !== null && ! isset($byCode[$node->parentCode]))
            ) {
                throw $this->failure('impossible_classifier_hierarchy', 'Classifier parent linkage is invalid.');
            }
        }

        foreach ($nodes as $node) {
            $visited = [];
            $cursor = $node;

            while ($cursor->parentCode !== null) {
                if (isset($visited[$cursor->code])) {
                    throw $this->failure('classifier_hierarchy_cycle', 'Classifier hierarchy contains a cycle.');
                }

                $visited[$cursor->code] = true;
                $cursor = $byCode[$cursor->parentCode];
            }
        }

        foreach ($nodes as $node) {
            if ($node->semanticLevel === ClassifierSemanticLevel::Section) {
                if ($node->parentCode !== null || $node->formalDepth !== 0) {
                    throw $this->failure('impossible_classifier_hierarchy', 'Classifier section roots are invalid.');
                }

                continue;
            }

            if ($node->parentCode === null
                || $byCode[$node->parentCode]->formalDepth >= $node->formalDepth
            ) {
                throw $this->failure('impossible_classifier_hierarchy', 'Classifier parent linkage is invalid.');
            }
        }
    }

    private function failure(string $code, string $message): ClassifierParserException
    {
        return ClassifierParserException::fatal($code, $message);
    }
}
