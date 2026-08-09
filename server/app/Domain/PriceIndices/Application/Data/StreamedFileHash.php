<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class StreamedFileHash
{
    public function __construct(
        public string $sha256,
        public int $size,
    ) {
    }
}
