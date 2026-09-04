<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ZipSafetyLimits
{
    public function __construct(
        public int $maxEntries,
        public int $maxSingleEntryUncompressedBytes,
        public int $maxTotalUncompressedBytes,
        public float $maxCompressionRatio,
        public int $maxTotalCompressedBytes = PHP_INT_MAX,
    ) {}
}
