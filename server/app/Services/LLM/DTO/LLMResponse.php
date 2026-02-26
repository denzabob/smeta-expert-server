<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

/**
 * DTO для ответа LLM провайдера
 */
final class LLMResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $rawText,
        public readonly array $json,
        public readonly int $latencyMs,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?float $costUsd = null,
        public readonly bool $usedJsonMode = false
    ) {}

    /**
     * Получить общее количество токенов
     */
    public function getTotalTokens(): int
    {
        return ($this->promptTokens ?? 0) + ($this->completionTokens ?? 0);
    }

    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'],
            model: $data['model'],
            rawText: $data['raw_text'],
            json: $data['json'] ?? [],
            latencyMs: $data['latency_ms'],
            promptTokens: $data['prompt_tokens'] ?? null,
            completionTokens: $data['completion_tokens'] ?? null,
            costUsd: $data['cost_usd'] ?? null,
            usedJsonMode: $data['used_json_mode'] ?? false
        );
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'raw_text' => $this->rawText,
            'json' => $this->json,
            'latency_ms' => $this->latencyMs,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'cost_usd' => $this->costUsd,
            'used_json_mode' => $this->usedJsonMode,
        ];
    }
}
