<?php

declare(strict_types=1);

namespace App\Services\LLM\Contracts;

use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMStreamEvent;

/**
 * Optional provider boundary for a real, cancellable chat stream.
 *
 * Providers which only implement LLMProviderInterface deliberately remain
 * available for the legacy synchronous chat path.
 */
interface LLMStreamingProviderInterface
{
    /** @return iterable<LLMStreamEvent> */
    public function streamChat(LLMChatRequest $request, LLMCancellationToken $cancellationToken): iterable;
}
