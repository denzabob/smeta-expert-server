<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;

class ResolveTrustedClassifierCandidateVersion
{
    public function __construct(
        private readonly TrustedClassifierCandidateRegistry $candidates,
        private readonly ResolveTrustedClassifierCandidateSource $sources,
        private readonly FindEquivalentClassifierVersion $versions,
    ) {}

    public function resolve(string $candidateKey): StatisticalClassifierVersion
    {
        $descriptor = $this->candidates->get($candidateKey);
        [$classifier, $source] = $this->sources->resolve($descriptor);
        $version = $this->versions->find(
            $descriptor,
            $classifier,
            $source,
            requireSameImport: false,
        );

        if ($version === null) {
            throw new ClassifierVersionActivationException(
                'candidate_version_not_staged',
                'The trusted classifier candidate has not been persisted.',
            );
        }

        return $version;
    }
}
