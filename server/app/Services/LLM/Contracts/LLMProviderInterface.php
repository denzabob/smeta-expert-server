<?php

declare(strict_types=1);

namespace App\Services\LLM\Contracts;

use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Enums\LLMCapability;

/**
 * Контракт провайдера LLM
 *
 * Провайдеры не строят промпт — только отправляют system/user и принимают ответ.
 * Провайдеры не решают fallback — это делает Router.
 */
interface LLMProviderInterface
{
    public function name(): string;

    public function model(): string;

    /** @return list<LLMCapability> */
    public function capabilities(): array;

    public function supportsJsonMode(): bool;

    public function isAvailable(): bool;

    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse;

    /** Выполнить chat completion без decomposition parser. */
    public function chat(LLMChatRequest $request): LLMChatResponse;
}
