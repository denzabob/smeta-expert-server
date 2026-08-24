<?php

namespace App\Domain\PriceIndices\Infrastructure\Persistence;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;

class ClassifierSourceFileRepository
{
    public function findByClassifierAndHash(int $classifierId, string $sha256): ?StatisticalClassifierSourceFile
    {
        return StatisticalClassifierSourceFile::query()
            ->where('classifier_id', $classifierId)
            ->where('sha256', $sha256)
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): StatisticalClassifierSourceFile
    {
        return StatisticalClassifierSourceFile::query()->create($attributes);
    }
}
