<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMCancellationToken
{
    /** @param \Closure(): bool $isCancelled */
    public function __construct(private readonly \Closure $isCancelled) {}

    public function isCancellationRequested(): bool
    {
        return (bool) ($this->isCancelled)();
    }
}
