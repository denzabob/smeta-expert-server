<?php

declare(strict_types=1);

namespace App\Services\LLM\Contracts;

use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMResponse;

/**
 * Контракт провайдера LLM
 * 
 * Провайдеры не строят промпт — только отправляют system/user и принимают ответ.
 * Провайдеры не решают fallback — это делает Router.
 */
interface LLMProviderInterface
{
    /**
     * Уникальное имя провайдера (openrouter, deepseek, mixtral)
     */
    public function name(): string;

    /**
     * Поддерживает ли провайдер JSON mode
     */
    public function supportsJsonMode(): bool;

    /**
     * Проверить доступность провайдера (ping)
     * 
     * @return bool true если провайдер доступен
     */
    public function isAvailable(): bool;

    /**
     * Сгенерировать декомпозицию работы
     * 
     * @param DecompositionPrompt $prompt Подготовленный промпт
     * @return LLMResponse Ответ провайдера
     * @throws \App\Services\LLM\Exceptions\LLMProviderException При ошибке провайдера
     */
    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse;
}
