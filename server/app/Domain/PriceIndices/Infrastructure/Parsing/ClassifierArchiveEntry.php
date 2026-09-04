<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

readonly class ClassifierArchiveEntry
{
    public function __construct(
        public int $index,
        public string $name,
        public int $uncompressedBytes,
        public int $compressedBytes,
        public bool $directory,
        public bool $encrypted = false,
        public bool $link = false,
        public bool $special = false,
        public ?string $crc32 = null,
    ) {}
}
