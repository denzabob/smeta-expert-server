<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMImageContent
{
    public function __construct(
        public readonly string $materialPublicId,
        public readonly string $name,
        public readonly string $mimeType,
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
        public readonly int $sourceBytes = 0,
    ) {}
}
