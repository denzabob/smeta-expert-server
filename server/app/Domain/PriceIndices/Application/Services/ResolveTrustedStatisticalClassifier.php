<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use Illuminate\Database\QueryException;

class ResolveTrustedStatisticalClassifier
{
    public function resolve(TrustedClassifierDescriptor $descriptor): StatisticalClassifier
    {
        $classifier = StatisticalClassifier::query()->where('code', $descriptor->code)->first();

        if ($classifier === null) {
            try {
                $classifier = StatisticalClassifier::query()->create([
                    'code' => $descriptor->code,
                    ...$descriptor->classifierIdentity(),
                ]);
            } catch (QueryException $exception) {
                if (! $this->isCodeRace($exception)) {
                    throw $exception;
                }

                $classifier = StatisticalClassifier::query()->where('code', $descriptor->code)->first();

                if ($classifier === null) {
                    throw $exception;
                }
            }
        }

        foreach ($descriptor->classifierIdentity() as $field => $expected) {
            if ($classifier->getAttribute($field) !== $expected) {
                throw new ClassifierAcquisitionException(
                    'classifier_identity_conflict',
                    "Existing classifier [{$descriptor->code}] conflicts with trusted identity field [{$field}]."
                );
            }
        }

        return $classifier;
    }

    private function isCodeRace(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), 'stat_classifiers_code_unique');
    }
}
