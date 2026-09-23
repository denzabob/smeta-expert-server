<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMCancellationToken
{
    /** @param \Closure(): bool $isCancelled @param ?\Closure(): void $onWait */
    public function __construct(
        private readonly \Closure $isCancelled,
        private readonly ?\Closure $onWait = null,
        public readonly ?string $correlationId = null,
    ) {}

    public function isCancellationRequested(): bool
    {
        return (bool) ($this->isCancelled)();
    }

    public function tick(): void
    {
        if ($this->onWait !== null) {
            ($this->onWait)();
        }
    }
}
