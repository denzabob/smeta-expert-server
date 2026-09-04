<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;

class ValidateClassifierCandidateSnapshot
{
    /** @return array<string, int|array<string, int>> */
    public function validate(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifierSourceFile $source,
        ParsedClassifierSnapshot $snapshot,
    ): array {
        if (! hash_equals($descriptor->sourceSha256, strtolower($source->sha256))) {
            $this->mismatch('candidate_source_sha_mismatch', 'source SHA-256');
        }

        $rawArtifactType = $source->metadata_json['artifact_type']
            ?? pathinfo($source->storage_path, PATHINFO_EXTENSION);
        $actualArtifactType = strtolower((string) $rawArtifactType);

        if ($actualArtifactType !== '' && $actualArtifactType !== strtolower($descriptor->expectedArtifactType)) {
            $this->mismatch('candidate_artifact_type_mismatch', 'artifact type');
        }

        if ($snapshot->parserCode !== $descriptor->parserCode
            || $snapshot->parserVersion !== $descriptor->parserVersion
        ) {
            $this->mismatch('candidate_parser_identity_mismatch', 'parser identity');
        }

        $this->assertSame($descriptor->expectedSectionsCount, $snapshot->sectionsCount, 'candidate_sections_count_mismatch', 'section count');
        $this->assertSame($descriptor->expectedDigitalNodesCount, $snapshot->digitalNodesCount, 'candidate_digital_nodes_count_mismatch', 'digital node count');
        $this->assertSame($descriptor->expectedTotalNodesCount, $snapshot->totalNodesCount, 'candidate_total_nodes_count_mismatch', 'total node count');
        $this->assertSame($descriptor->expectedWarningsCount, count($snapshot->warnings), 'candidate_warnings_count_mismatch', 'warning count');

        if ($snapshot->validationSummary->fatalErrors !== []) {
            $this->mismatch('candidate_validation_errors_present', 'generic validation result');
        }

        $this->assertSame(
            $descriptor->expectedWarningsCount,
            count($snapshot->validationSummary->warnings),
            'candidate_warnings_count_mismatch',
            'validation warning count',
        );

        $metrics = $snapshot->validationSummary->metrics;
        $notesCount = $metrics['notes_count'] ?? null;
        $levelCounts = $metrics['level_counts'] ?? null;

        if (! is_int($notesCount)) {
            $this->mismatch('candidate_notes_count_mismatch', 'notes count');
        }

        $this->assertSame($descriptor->expectedNotesCount, $notesCount, 'candidate_notes_count_mismatch', 'notes count');

        if (! is_array($levelCounts)) {
            $this->mismatch('candidate_level_count_mismatch', 'semantic level profile');
        }

        foreach ($descriptor->expectedLevelCounts as $level => $expected) {
            $actual = $levelCounts[$level] ?? null;

            if (! is_int($actual) || $actual !== $expected) {
                throw new ClassifierCandidateStagingException(
                    'candidate_level_count_mismatch',
                    'The parsed classifier candidate has an unexpected semantic level count.',
                    'validating',
                    ['level' => $level, 'expected' => $expected, 'actual' => is_int($actual) ? $actual : null],
                );
            }
        }

        $nodesByCode = [];

        foreach ($snapshot->nodes as $node) {
            $nodesByCode[$node->code] = $node;
        }

        $control = $nodesByCode[$descriptor->controlNodeCode] ?? null;

        if (! $control instanceof ParsedClassifierNode
            || $control->name !== $descriptor->controlNodeName
            || $control->semanticLevel !== $descriptor->controlNodeLevel
            || $control->parentCode !== $descriptor->controlNodeParentCode
        ) {
            $this->mismatch('candidate_control_node_mismatch', 'control node');
        }

        foreach ($descriptor->controlAncestorParents as $code => $parentCode) {
            $ancestor = $nodesByCode[$code] ?? null;

            if (! $ancestor instanceof ParsedClassifierNode || $ancestor->parentCode !== $parentCode) {
                throw new ClassifierCandidateStagingException(
                    'candidate_control_ancestor_mismatch',
                    'The parsed classifier candidate has an unexpected control-node ancestor chain.',
                    'validating',
                    ['code' => $code],
                );
            }
        }

        return [
            'sections_count' => $snapshot->sectionsCount,
            'digital_nodes_count' => $snapshot->digitalNodesCount,
            'total_nodes_count' => $snapshot->totalNodesCount,
            'notes_count' => $notesCount,
            'warnings_count' => count($snapshot->warnings),
            'level_counts' => $descriptor->expectedLevelCounts,
        ];
    }

    private function assertSame(int $expected, int $actual, string $code, string $label): void
    {
        if ($expected !== $actual) {
            throw new ClassifierCandidateStagingException(
                $code,
                "The parsed classifier candidate has an unexpected {$label}.",
                'validating',
                ['expected' => $expected, 'actual' => $actual],
            );
        }
    }

    private function mismatch(string $code, string $label): never
    {
        throw new ClassifierCandidateStagingException(
            $code,
            "The parsed classifier candidate has an unexpected {$label}.",
            'validating',
        );
    }
}
