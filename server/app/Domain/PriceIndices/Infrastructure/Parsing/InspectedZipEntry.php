<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

final readonly class InspectedZipEntry
{
    public function __construct(
        public int $index,
        public string $name,
        public int $uncompressedBytes,
        public int $compressedBytes,
        public string $crc32,
        public bool $directory,
    ) {}
}
