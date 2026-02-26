<?php

namespace App\Services\AI;

use App\Models\WorkPreset;

/**
 * Репозиторий для работы с пресетами работ
 */
class WorkPresetRepository
{
    public function __construct(
        private ContextNormalizer $normalizer
    ) {}

    /**
     * Найти точное совпадение пресета по context_hash и normalized_title
     * 
     * Возвращает только активные пресеты (verified или candidate).
     * Приоритет: verified > candidate, затем по usage_count desc.
     */
    public function findExact(string $contextHash, string $normalizedTitle): ?WorkPreset
    {
        return WorkPreset::query()
            ->byContext($contextHash, $normalizedTitle)
            ->active()
            ->orderByPriority()
            ->first();
    }

    /**
     * Найти пресет по fingerprint
     */
    public function findByFingerprint(string $contextHash, string $normalizedTitle, string $fingerprint): ?WorkPreset
    {
        return WorkPreset::query()
            ->where('context_hash', $contextHash)
            ->where('normalized_title', $normalizedTitle)
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    /**
     * Создать или обновить пресет на основе feedback
     * 
     * Правила:
     * - Если найден по (context_hash, normalized_title, fingerprint): usage_count++
     * - Если usage_count >= 10 и status = draft: повысить до candidate
     * - Если не найден: создать новый draft
     */
    public function captureFromFeedback(
        string $normalizedTitle,
        string $contextHash,
        array $hashableContext,
        array $steps,
        string $source = WorkPreset::SOURCE_MANUAL
    ): WorkPreset {
        $fingerprint = $this->normalizer->makeFingerprint($steps);
        $totalHours = array_reduce($steps, fn($sum, $step) => $sum + (float)($step['hours'] ?? 0), 0.0);
        
        // Пытаемся найти существующий пресет
        $existing = $this->findByFingerprint($contextHash, $normalizedTitle, $fingerprint);
        
        if ($existing) {
            // Увеличиваем счетчик и проверяем на повышение статуса
            $existing->incrementUsage();
            return $existing;
        }
        
        // Создаем новый draft пресет
        return WorkPreset::create([
            'normalized_title' => $normalizedTitle,
            'context_hash' => $contextHash,
            'context_json' => $hashableContext,
            'steps_json' => $steps,
            'total_hours' => $totalHours,
            'fingerprint' => $fingerprint,
            'usage_count' => 1,
            'status' => WorkPreset::STATUS_DRAFT,
            'source' => $source,
        ]);
    }

    /**
     * Получить все пресеты для контекста (для отладки/администрирования)
     */
    public function getAllForContext(string $contextHash, string $normalizedTitle): \Illuminate\Database\Eloquent\Collection
    {
        return WorkPreset::query()
            ->byContext($contextHash, $normalizedTitle)
            ->orderByPriority()
            ->get();
    }

    /**
     * Пометить пресет как deprecated
     */
    public function deprecate(int $presetId): bool
    {
        return WorkPreset::where('id', $presetId)
            ->update(['status' => WorkPreset::STATUS_DEPRECATED]) > 0;
    }

    /**
     * Верифицировать пресет (только вручную)
     */
    public function verify(int $presetId): bool
    {
        return WorkPreset::where('id', $presetId)
            ->whereIn('status', [WorkPreset::STATUS_DRAFT, WorkPreset::STATUS_CANDIDATE])
            ->update(['status' => WorkPreset::STATUS_VERIFIED]) > 0;
    }
}
