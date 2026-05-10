<?php

namespace App\Services\AI;

use App\Services\LLM\Exceptions\LLMUnavailableException;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\Prompts\DecompositionPromptBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $correlationId = (string) Str::uuid();

        try {
            $llmResponse = $this->llmRouter->generateDecomposition($prompt, $correlationId);
        } catch (LLMUnavailableException $e) {
            Log::warning('DecompositionService: LLM unavailable, using local fallback', [
                'title' => $title,
                'context_hash' => $contextHash,
                'correlation_id' => $correlationId,
                'failover_chain' => $e->getFailoverChain(),
                'error' => $e->getMessage(),
            ]);

            $fallbackSteps = $this->buildLocalFallbackSteps($title, $desiredHours, $note);

            return $this->buildResponse(
                source: 'fallback_local',
                steps: $fallbackSteps,
                meta: [
                    'context_hash' => $contextHash,
                    'is_draft' => true,
                    'correlation_id' => $correlationId,
                    'fallback_reason' => 'llm_unavailable',
                    'failover_chain' => $e->getFailoverChain(),
                ]
            );
        }
        
        return $this->buildResponse(
            source: 'ai',
            steps: $llmResponse->json['steps'] ?? [],
            meta: [
                'context_hash' => $contextHash,
                'is_draft' => true,
                'correlation_id' => $correlationId,
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

    /**
     * Локальный безопасный fallback, когда LLM недоступен.
     *
     * Возвращает нейтральную базовую декомпозицию, совместимую с фронтендом.
     */
    private function buildLocalFallbackSteps(string $title, ?float $desiredHours = null, ?string $note = null): array
    {
        $totalHours = max(0.1, round((float) ($desiredHours ?? 1.5), 2));
        $fallbackBasis = 'Локальный шаблон декомпозиции; требуется проверка специалистом';

        if ($totalHours <= 0.75) {
            return [
                [
                    'title' => $this->truncateStepTitle($title),
                    'hours' => $totalHours,
                    'basis' => $fallbackBasis,
                ],
            ];
        }

        if ($totalHours <= 2.5) {
            $prepareHours = round(max(0.25, min(0.5, $totalHours * 0.3)), 2);
            $mainHours = round(max(0.25, $totalHours - $prepareHours), 2);

            return [
                [
                    'title' => 'Подготовка и разметка',
                    'hours' => $prepareHours,
                    'basis' => $fallbackBasis,
                ],
                [
                    'title' => $this->truncateStepTitle($title),
                    'hours' => round(max(0.1, $mainHours), 2),
                    'basis' => $fallbackBasis,
                ],
            ];
        }

        $prepareHours = round(max(0.5, $totalHours * 0.2), 2);
        $finishHours = round(max(0.5, min(1.0, $totalHours * 0.2)), 2);
        $mainHours = round(max(0.25, $totalHours - $prepareHours - $finishHours), 2);

        $mainTitle = $this->truncateStepTitle($title);
        if (!empty($note)) {
            $mainTitle = $this->truncateStepTitle($mainTitle . ' (' . $this->extractShortNote($note) . ')');
        }

        return [
            [
                'title' => 'Подготовка, доступ и защита зоны работ',
                'hours' => $prepareHours,
                'basis' => $fallbackBasis,
            ],
            [
                'title' => $mainTitle,
                'hours' => $mainHours,
                'basis' => $fallbackBasis,
            ],
            [
                'title' => 'Проверка результата и завершение работ',
                'hours' => round(max(0.1, $finishHours), 2),
                'basis' => $fallbackBasis,
            ],
        ];
    }

    private function truncateStepTitle(string $title, int $limit = 160): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title)) ?: 'Выполнение работы';

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, $limit - 1)) . '…';
    }

    private function extractShortNote(string $note, int $limit = 60): string
    {
        $note = trim(preg_replace('/\s+/u', ' ', strip_tags($note)));

        if ($note === '') {
            return '';
        }

        if (mb_strlen($note) <= $limit) {
            return $note;
        }

        return rtrim(mb_substr($note, 0, $limit - 1)) . '…';
    }
}
