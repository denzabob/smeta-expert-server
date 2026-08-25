<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Application\Data\ClassifierExpectedProfile;
use App\Domain\PriceIndices\Application\Data\ClassifierParserIdentity;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationIssue;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\ZipSafetyLimits;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;

class Okpd2RosstatDocxParser
{
    public const PARSER_CODE = 'okpd2_rosstat_docx';

    public const PARSER_VERSION = 1;

    public function __construct(
        private readonly SafeZipArchiveInspector $archives,
        private readonly Okpd2DocxSecurityInspector $docxSecurity,
        private readonly Okpd2WordprocessingMlReader $wordprocessingMl,
        private readonly ClassifierHierarchyResolver $hierarchy,
        private readonly ClassifierSnapshotValidator $validator,
    ) {}

    public function identity(): ClassifierParserIdentity
    {
        return new ClassifierParserIdentity(self::PARSER_CODE, self::PARSER_VERSION);
    }

    public function parse(
        string $absoluteArtifactPath,
        ?ClassifierExpectedProfile $expectedProfile = null,
    ): ParsedClassifierSnapshot {
        $startedAt = hrtime(true);
        $peakMemoryBefore = memory_get_peak_usage(true);
        $config = $this->configuration();
        $parts = $config['parts'];
        $outer = $this->archives->open($absoluteArtifactPath, $this->limits($config['outer_zip']));
        $rawNodes = [];
        $rawRowsCount = 0;
        $notesCount = 0;

        try {
            $expectedPartNames = array_column($parts, 'filename');

            foreach ($outer->entries as $entry) {
                if (! $entry->directory
                    && strtolower(pathinfo($entry->name, PATHINFO_EXTENSION)) !== 'docx'
                ) {
                    throw ClassifierParserException::fatal(
                        'unexpected_outer_zip_entry',
                        'The outer classifier ZIP contains an unexpected non-DOCX entry.'
                    );
                }
            }

            if ($outer->fileNames() !== $expectedPartNames) {
                throw ClassifierParserException::fatal(
                    'incompatible_outer_zip_layout',
                    'The outer classifier ZIP part order is incompatible with parser version 1.'
                );
            }

            foreach ($parts as $part) {
                $docxTemporary = $outer->materialize($part['filename'], 'okpd2_docx_');

                try {
                    $docx = $this->archives->open($docxTemporary->path, $this->limits($config['docx_zip']));

                    try {
                        $this->docxSecurity->inspect($docx, $config['max_control_xml_bytes']);
                        $documentXml = $docx->materialize('word/document.xml', 'okpd2_xml_');

                        try {
                            $parsedPart = $this->wordprocessingMl->read(
                                documentXmlPath: $documentXml->path,
                                sourcePart: $part['filename'],
                                expectedSections: $part['sections'],
                                maxDocumentXmlBytes: $config['max_document_xml_bytes'],
                            );
                        } finally {
                            $documentXml->close();
                        }
                    } finally {
                        $docx->close();
                    }
                } finally {
                    $docxTemporary->close();
                }

                array_push($rawNodes, ...$parsedPart->nodes);
                $rawRowsCount += $parsedPart->rowsCount;
                $notesCount += $parsedPart->notesCount;
            }
        } finally {
            $outer->close();
        }

        [$deduplicated, $warnings, $duplicatesCount] = $this->deduplicate($rawNodes);
        $hierarchy = $this->hierarchy->resolve($deduplicated);
        $profile = $expectedProfile ?? new ClassifierExpectedProfile(
            requiredSections: range('A', 'U'),
            minimumDigitalNodes: $config['minimum_digital_nodes'],
        );
        $counts = $this->validator->validate($hierarchy['nodes'], $profile);
        $elapsedSeconds = max(0.000001, (hrtime(true) - $startedAt) / 1_000_000_000);
        $metrics = [
            'parts_count' => count($parts),
            'raw_rows_count' => $rawRowsCount,
            'notes_count' => $notesCount,
            'identical_duplicate_rows' => $duplicatesCount,
            'skipped_immediate_parents' => $hierarchy['skipped_immediate_parents'],
            'level_counts' => $counts['level_counts'],
            'elapsed_parse_ms' => round($elapsedSeconds * 1000, 3),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $peakMemoryBefore),
            'nodes_per_second' => round($counts['total_nodes_count'] / $elapsedSeconds, 2),
        ];
        $summary = new ClassifierValidationSummary([], $warnings, $metrics);

        return new ParsedClassifierSnapshot(
            parserCode: self::PARSER_CODE,
            parserVersion: self::PARSER_VERSION,
            sectionsCount: $counts['sections_count'],
            digitalNodesCount: $counts['digital_nodes_count'],
            totalNodesCount: $counts['total_nodes_count'],
            nodes: $hierarchy['nodes'],
            warnings: $warnings,
            validationSummary: $summary,
        );
    }

    /**
     * @param  list<RawClassifierNode>  $rawNodes
     * @return array{list<RawClassifierNode>, list<ClassifierValidationIssue>, int}
     */
    private function deduplicate(array $rawNodes): array
    {
        $byCode = [];
        $deduplicated = [];
        $warnings = [];
        $duplicates = 0;

        foreach ($rawNodes as $node) {
            $existing = $byCode[$node->code] ?? null;

            if (! $existing instanceof RawClassifierNode) {
                $byCode[$node->code] = $node;
                $deduplicated[] = $node;

                continue;
            }

            if ($existing->name !== $node->name
                || $existing->semanticLevel !== $node->semanticLevel
                || $existing->sectionCode !== $node->sectionCode
                || $existing->notes !== $node->notes
            ) {
                throw ClassifierParserException::fatal(
                    'conflicting_classifier_duplicate',
                    'The classifier contains conflicting rows for one canonical code.'
                );
            }

            $duplicates++;
            $warnings[] = new ClassifierValidationIssue(
                code: 'identical_classifier_duplicate',
                message: 'An identical classifier row was deduplicated.',
                context: ['code' => $node->code],
            );
        }

        return [$deduplicated, $warnings, $duplicates];
    }

    /** @return array{version: int, parts: list<array{filename: string, sections: list<string>}>, minimum_digital_nodes: int, outer_zip: array<string, int|float>, docx_zip: array<string, int|float>, max_document_xml_bytes: int, max_control_xml_bytes: int} */
    private function configuration(): array
    {
        $config = config('price_indices.classifier_parsers.'.self::PARSER_CODE);

        if (! is_array($config)
            || ($config['version'] ?? null) !== self::PARSER_VERSION
            || ! is_array($config['parts'] ?? null)
            || count($config['parts']) !== 2
            || ! is_int($config['minimum_digital_nodes'] ?? null)
            || ! is_int($config['max_document_xml_bytes'] ?? null)
            || ! is_int($config['max_control_xml_bytes'] ?? null)
            || ! is_array($config['outer_zip'] ?? null)
            || ! is_array($config['docx_zip'] ?? null)
        ) {
            throw ClassifierParserException::fatal(
                'invalid_classifier_parser_configuration',
                'The OKPD2 parser configuration is invalid.'
            );
        }

        foreach ($config['parts'] as $part) {
            if (! is_array($part)
                || ! is_string($part['filename'] ?? null)
                || ! is_array($part['sections'] ?? null)
                || $part['sections'] === []
            ) {
                throw ClassifierParserException::fatal(
                    'invalid_classifier_parser_configuration',
                    'The OKPD2 parser part descriptor is invalid.'
                );
            }
        }

        return $config;
    }

    /** @param array<string, int|float> $config */
    private function limits(array $config): ZipSafetyLimits
    {
        $values = [
            $config['max_entries'] ?? null,
            $config['max_single_entry_uncompressed_bytes'] ?? null,
            $config['max_total_uncompressed_bytes'] ?? null,
            $config['max_compression_ratio'] ?? null,
        ];

        if (array_filter($values, fn (mixed $value): bool => ! is_int($value) && ! is_float($value)) !== []
            || min($values) <= 0
        ) {
            throw ClassifierParserException::fatal(
                'invalid_classifier_parser_configuration',
                'The OKPD2 parser ZIP limits are invalid.'
            );
        }

        return new ZipSafetyLimits(
            maxEntries: (int) $values[0],
            maxSingleEntryUncompressedBytes: (int) $values[1],
            maxTotalUncompressedBytes: (int) $values[2],
            maxCompressionRatio: (float) $values[3],
        );
    }
}
