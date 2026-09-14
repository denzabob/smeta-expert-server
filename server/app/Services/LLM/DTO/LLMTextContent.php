<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMTextContent
{
    public function __construct(public readonly string $text) {}
}
