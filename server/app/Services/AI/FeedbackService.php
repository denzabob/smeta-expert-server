<?php

namespace App\Services\AI;

use App\Models\WorkPreset;

/**
 * Сервис обратной связи для накопления пресетов
 */
class FeedbackService
{
    public function __construct(
        private ContextNormalizer $normalizer,
        private WorkPresetRepository $presetRepository
    ) {}

    /**
     * Захватить финальные этапы для накопления пресетов
     * 
     * Правила:
     * - Считаем fingerprint
     * - Ищем существующий work_presets по (context_hash, normalized_title, fingerprint)
     * - Если найден: usage_count++, если usage_count >= 10 и status draft => candidate
     * - Если не найден: создать draft
     * - Никаких авто-verified. Verified только ручной модерацией.
     * 
     * @param string $title Название работы
     * @param array $context Контекст работы
     * @param array $finalSteps Финальные этапы
     * @param string $source Источник: 'ai' или 'manual'
     */
    public function capture(string $title, array $context, array $finalSteps, string $source = WorkPreset::SOURCE_MANUAL): void
    {
        // Пропускаем если нет этапов
        if (empty($finalSteps)) {
            return;
        }
        
        // Нормализация
        $normalized = $this->normalizer->normalize($title, $context);
        $normalizedTitle = $normalized['normalized_title'];
        $hashableContext = $normalized['hashable_context'];
        $contextHash = $normalized['context_hash'];
        
        // Очищаем этапы от лишних полей
        $cleanSteps = $this->cleanSteps($finalSteps);
        
        // Передаем в репозиторий для создания/обновления
        $this->presetRepository->captureFromFeedback(
            $normalizedTitle,
            $contextHash,
            $hashableContext,
            $cleanSteps,
            $source
        );
    }

    /**
     * Очистка этапов от лишних полей
     * 
     * Оставляем только: title, hours, basis, input_data
     * notes/комментарии не сохраняем в пресетах
     */
    private function cleanSteps(array $steps): array
    {
        $allowed = ['title', 'hours', 'basis', 'input_data'];
        
        return array_map(function ($step) use ($allowed) {
            $clean = [];
            foreach ($allowed as $key) {
                if (isset($step[$key]) && $step[$key] !== null && $step[$key] !== '') {
                    $clean[$key] = $key === 'hours' 
                        ? round((float)$step[$key], 2) 
                        : trim($step[$key]);
                }
            }
            return $clean;
        }, $steps);
    }
}
