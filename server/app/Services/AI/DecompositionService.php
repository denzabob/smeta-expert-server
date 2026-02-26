<?php

namespace App\Services\AI;

use App\Services\LLM\Exceptions\LLMUnavailableException;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\Prompts\DecompositionPromptBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Сервис декомпозиции работ (Tier 1 -> Tier 3)
 * 
 * Реализует трехуровневое кеширование:
 * - Tier 1: Точное совпадение по context_hash + normalized_title (candidate/verified)
 * - Tier 2: Fuzzy matching (не реализован в MVP, зарезервирован для pgvector)
 * - Tier 3: AI генерация через LLMRouter (с failover)
 */
class DecompositionService
{
    public function __construct(
        private ContextNormalizer $normalizer,
        private WorkPresetRepository $presetRepository,
        private DecompositionPromptBuilder $promptBuilder,
        private LLMRouter $llmRouter
    ) {}

    /**
     * Получить предложение по декомпозиции работы
     * 
     * @param string $title Название работы
     * @param array $context Контекст работы
     * @param float|null $desiredHours Желаемое количество часов
     * @param int|null $userId ID пользователя (если null - берётся из Auth)
     * @return array Структурированный ответ с source, meta и suggestion
     * @throws LLMUnavailableException
     */
    public function suggest(string $title, array $context, ?float $desiredHours = null, ?string $note = null, ?int $userId = null): array
    {
        // Определяем user_id
        $userId = $userId ?? Auth::id();
        
        // Нормализация
        $normalized = $this->normalizer->normalize($title, $context);
        $normalizedTitle = $normalized['normalized_title'];
        $hashableContext = $normalized['hashable_context'];
        $contextHash = $normalized['context_hash'];
        
        // Tier 1: Поиск точного совпадения
        $preset = $this->presetRepository->findExact($contextHash, $normalizedTitle);
        
        if ($preset) {
            return $this->buildResponse(
                source: 'tier1_exact',
                steps: $preset->steps_json,
                meta: [
                    'context_hash' => $contextHash,
                    'preset_id' => $preset->id,
                    'status' => $preset->status,
                    'usage_count' => $preset->usage_count,
                    'is_draft' => false,
                ]
            );
        }
        
        // Tier 2: Fuzzy matching (зарезервирован для pgvector)
        // TODO: Добавить после внедрения pgvector
        
        // Tier 3: AI генерация через LLMRouter
        // Устанавливаем user_id для логирования
        $this->llmRouter->setUserId($userId);
        
        $prompt = $this->promptBuilder->build($title, $hashableContext, $desiredHours, $note);
        $llmResponse = $this->llmRouter->generateDecomposition($prompt);
        
        return $this->buildResponse(
            source: 'ai',
            steps: $llmResponse->json['steps'] ?? [],
            meta: [
                'context_hash' => $contextHash,
                'is_draft' => true,
                'provider' => $llmResponse->provider,
                'model' => $llmResponse->model,
                'latency_ms' => $llmResponse->latencyMs,
                'tokens' => [
                    'prompt' => $llmResponse->promptTokens,
                    'completion' => $llmResponse->completionTokens,
                ],
                'cost_usd' => $llmResponse->costUsd,
            ]
        );
    }

    /**
     * Построить структурированный ответ
     */
    private function buildResponse(string $source, array $steps, array $meta): array
    {
        // Рассчитать общее время
        $totalHours = array_reduce($steps, fn($sum, $step) => $sum + (float)($step['hours'] ?? 0), 0.0);
        
        return [
            'source' => $source,
            'meta' => $meta,
            'suggestion' => [
                'steps' => $steps,
                'totals' => [
                    'hours' => round($totalHours, 2),
                ],
            ],
        ];
    }
}
