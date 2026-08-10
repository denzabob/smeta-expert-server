<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;

final class StatisticalImportPreviewCacheKey
{
    public function forSourceFile(
        StatisticalSourceFile $sourceFile,
        string $importerCode,
        string $importerVersion,
    ): string {
        if (! preg_match('/^[a-f0-9]{64}$/i', $sourceFile->sha256)
            || $importerCode === ''
            || $importerVersion === ''
        ) {
            throw new PriceIndicesInvariantViolation('Preview cache identity is incomplete.');
        }

        return hash('sha256', strtolower($sourceFile->sha256).'|'.$importerCode.'|'.$importerVersion);
    }

    public function lockName(string $cacheKey): string
    {
        return 'price-indices:preview:'.$cacheKey;
    }
}
