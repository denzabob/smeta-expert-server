<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

final readonly class InspectedZipEntry extends ClassifierArchiveEntry
{
    public function __construct(
        int $index,
        string $name,
        int $uncompressedBytes,
        int $compressedBytes,
        string $crc32,
        bool $directory,
    ) {
        parent::__construct(
            index: $index,
            name: $name,
            uncompressedBytes: $uncompressedBytes,
            compressedBytes: $compressedBytes,
            directory: $directory,
            crc32: $crc32,
        );
    }
}
