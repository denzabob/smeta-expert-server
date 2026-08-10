<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ImportPreviewResult;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;

final class BuildImportPreviewPayload
{
    /** @return array<string, mixed> */
    public function execute(
        StatisticalSourceFile $sourceFile,
        ImportPreviewResult $preview,
    ): array {
        return [
            'source_file' => [
                'public_id' => $sourceFile->public_id,
                'original_filename' => $sourceFile->original_filename,
                'sha256' => $sourceFile->sha256,
                'reporting_period' => [
                    'year' => $sourceFile->reporting_year,
                    'month' => $sourceFile->reporting_month,
                ],
                'status' => $sourceFile->status->value,
            ],
            'importer' => [
                'code' => $preview->workbook['importer_code'],
                'version' => $preview->workbook['importer_version'],
            ],
            'workbook' => [
                'sheets_total' => $preview->structure['sheets_total'],
                'supported_sheets' => $preview->structure['supported_sheets'],
                'ignored_sheets' => $preview->structure['ignored_sheets'],
                'detected_years' => $preview->structure['detected_years'],
                'detected_comparison_bases' => $preview->structure['comparison_bases'],
                'detected_topologies' => $preview->structure['topologies'],
            ],
            'counts' => [
                'commodity_occurrences' => $preview->counts['commodity_occurrences'],
                'unique_classifier_items' => $preview->counts['unique_commodity_codes'],
                'observation_candidates' => $preview->counts['observation_candidates'],
                'numeric' => $preview->counts['numeric'],
                'missing' => $preview->counts['missing'],
                'footnoted' => $preview->counts['special_footnoted'],
                'warnings' => $preview->counts['warnings'],
                'fatal_errors' => $preview->counts['fatal_errors'],
            ],
            'samples' => array_slice(
                $preview->sampleRecords,
                0,
                (int) config('price_indices.imports.preview_sample_limit', 50),
            ),
        ];
    }
}
