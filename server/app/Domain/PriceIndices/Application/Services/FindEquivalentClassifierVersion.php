<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;

class FindEquivalentClassifierVersion
{
    public function __construct(private readonly FindEquivalentReadyClassifierImport $readyImports) {}

    public function find(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
        ?StatisticalClassifierImport $readyImport = null,
        bool $lockForUpdate = false,
        bool $requireSameImport = true,
    ): ?StatisticalClassifierVersion {
        $query = StatisticalClassifierVersion::query()
            ->with('classifierImport')
            ->where('classifier_id', $classifier->id)
            ->where('version_label', $descriptor->versionLabel);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $version = $query->first();

        if ($version === null) {
            return null;
        }

        $import = $version->classifierImport;
        $matches = $version->effective_from?->toDateString() === $descriptor->effectiveFrom
            && $import instanceof StatisticalClassifierImport
            && (! $requireSameImport || ($readyImport !== null && $import->is($readyImport)))
            && $import->status === ClassifierImportStatus::Ready
            && $import->classifier_id === $classifier->id
            && $import->source_file_id === $source->id
            && $import->parser_code === $descriptor->parserCode
            && $import->parser_version === (string) $descriptor->parserVersion
            && $this->readyImports->hasCandidateProvenance($import, $descriptor);

        if (! $matches) {
            throw new ClassifierCandidateStagingException(
                'candidate_version_conflict',
                'An existing classifier version label has conflicting candidate provenance.',
                'version_preflight',
                ['version_label' => $descriptor->versionLabel],
            );
        }

        return $version;
    }
}
