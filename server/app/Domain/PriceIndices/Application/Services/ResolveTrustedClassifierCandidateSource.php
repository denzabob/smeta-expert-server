<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;

class ResolveTrustedClassifierCandidateSource
{
    /** @return array{StatisticalClassifier, StatisticalClassifierSourceFile} */
    public function resolve(TrustedClassifierCandidateDescriptor $descriptor): array
    {
        $classifier = StatisticalClassifier::query()
            ->where('code', $descriptor->classifierCode)
            ->first();

        if ($classifier === null) {
            $this->notAvailable();
        }

        $source = StatisticalClassifierSourceFile::query()
            ->where('classifier_id', $classifier->id)
            ->where('sha256', $descriptor->sourceSha256)
            ->first();

        if ($source === null) {
            $this->notAvailable();
        }

        if ($source->trust_tier !== ClassifierSourceTrustTier::OfficialAuthoritative) {
            throw new ClassifierCandidateStagingException(
                'source_artifact_not_trusted',
                'The exact classifier source artifact does not have an accepted trust tier.',
                'source_lookup',
                ['trust_tier' => $source->trust_tier->value],
            );
        }

        return [$classifier, $source];
    }

    private function notAvailable(): never
    {
        throw new ClassifierCandidateStagingException(
            'source_artifact_not_available',
            'The exact trusted classifier source artifact is not available.',
            'source_lookup',
        );
    }
}
