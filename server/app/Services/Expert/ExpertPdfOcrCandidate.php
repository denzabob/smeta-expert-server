<?php

namespace App\Services\Expert;

final readonly class ExpertPdfOcrCandidate
{
    public function __construct(
        public string $projectPublicId,
        public string $materialPublicId,
        public string $name,
        public string $mimeType,
        public string $bytes,
        public string $sha256,
        public int $pageCount,
    ) {}
}
