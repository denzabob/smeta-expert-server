<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;

class FindEquivalentReadyClassifierImport
{
    public function find(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifierSourceFile $source,
        ?int $excludeImportId = null,
    ): ?StatisticalClassifierImport {
        $query = StatisticalClassifierImport::query()
            ->where('classifier_id', $source->classifier_id)
            ->where('source_file_id', $source->id)
            ->where('status', ClassifierImportStatus::Ready)
            ->where('parser_code', $descriptor->parserCode)
            ->where('parser_version', (string) $descriptor->parserVersion)
            ->orderBy('attempt');

        if ($excludeImportId !== null) {
            $query->whereKeyNot($excludeImportId);
        }

        foreach ($query->get() as $import) {
            if ($this->hasCandidateProvenance($import, $descriptor)) {
                return $import;
            }
        }

        return null;
    }

    public function hasCandidateProvenance(
        StatisticalClassifierImport $import,
        TrustedClassifierCandidateDescriptor $descriptor,
    ): bool {
        $summary = $import->validation_summary_json;

        return is_array($summary)
            && ($summary['candidate_key'] ?? null) === $descriptor->candidateKey
            && ($summary['candidate_fingerprint'] ?? null) === $descriptor->fingerprint()
            && ($summary['version_label'] ?? null) === $descriptor->versionLabel
            && ($summary['effective_from'] ?? null) === $descriptor->effectiveFrom
            && ($summary['source']['sha256'] ?? null) === $descriptor->sourceSha256
            && ($summary['parser']['code'] ?? null) === $descriptor->parserCode
            && ($summary['parser']['version'] ?? null) === $descriptor->parserVersion
            && ($summary['metrics']['sections_count'] ?? null) === $descriptor->expectedSectionsCount
            && ($summary['metrics']['digital_nodes_count'] ?? null) === $descriptor->expectedDigitalNodesCount
            && ($summary['metrics']['total_nodes_count'] ?? null) === $descriptor->expectedTotalNodesCount
            && ($summary['metrics']['warnings_count'] ?? null) === $descriptor->expectedWarningsCount
            && ($summary['notes_count'] ?? null) === $descriptor->expectedNotesCount
            && ($summary['level_counts'] ?? null) === $descriptor->expectedLevelCounts;
    }
}
