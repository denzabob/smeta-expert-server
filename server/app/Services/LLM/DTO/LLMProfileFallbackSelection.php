<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final readonly class LLMProfileFallbackSelection
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {}
}
