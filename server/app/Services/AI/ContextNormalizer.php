<?php

namespace App\Services\AI;

/**
 * Сервис нормализации контекста для работы с пресетами
 * 
 * Обеспечивает стабильный normalized_title, выделение hashable context,
 * расчет context_hash и fingerprint.
 */
class ContextNormalizer
{
    /**
     * Whitelist ключей контекста, которые участвуют в хешировании
     */
    private const HASHABLE_CONTEXT_KEYS = [
        'domain',           // Домен: furniture, renovation, construction
        'action_type',      // Тип действия: dismantle, install, repair
        'object_type',      // Тип объекта: kitchen, wardrobe, bathroom
        'material',         // Материал: wood, metal, plastic
        'constraints',      // Ограничения: narrow_passage, high_floor
        'site_state',       // Состояние объекта: empty, occupied
        'appliances',       // Техника: with_appliances, no_appliances
        'floor_access',     // Доступ: elevator, stairs, ground_floor
    ];

    /**
     * Нормализация заголовка работы
     * 
     * - lowercase
     * - trim
     * - collapse multiple spaces
     * - удаление спецсимволов (unicode-safe)
     */
    public function normalizeTitle(string $title): string
    {
        // Приводим к нижнему регистру (unicode-safe)
        $normalized = mb_strtolower($title, 'UTF-8');
        
        // Удаляем лишние пробелы по краям
        $normalized = trim($normalized);
        
        // Заменяем множественные пробелы на одинарный
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        
        // Удаляем спецсимволы, оставляем буквы, цифры, пробелы, дефисы
        // Unicode-safe: \p{L} - любые буквы, \p{N} - любые цифры
        $normalized = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $normalized);
        
        // Финальный trim после удаления символов
        return trim($normalized);
    }

    /**
     * Извлечение хешируемого контекста
     * 
     * Фильтрует только значимые поля, приводит значения к нижнему регистру,
     * сортирует по ключам для стабильности хеша.
     */
    public function extractHashableContext(array $rawContext): array
    {
        $hashable = [];
        
        foreach (self::HASHABLE_CONTEXT_KEYS as $key) {
            if (isset($rawContext[$key]) && $rawContext[$key] !== null && $rawContext[$key] !== '') {
                $value = $rawContext[$key];
                
                // Приводим строковые значения к нижнему регистру
                if (is_string($value)) {
                    $value = mb_strtolower(trim($value), 'UTF-8');
                } elseif (is_array($value)) {
                    // Для массивов - сортируем и приводим к нижнему регистру
                    $value = array_map(function ($v) {
                        return is_string($v) ? mb_strtolower(trim($v), 'UTF-8') : $v;
                    }, $value);
                    sort($value);
                }
                
                $hashable[$key] = $value;
            }
        }
        
        // Сортируем по ключам для стабильности
        ksort($hashable);
        
        return $hashable;
    }

    /**
     * Создание хеша контекста
     * 
     * Комбинирует normalized_title и hashable context в MD5 хеш.
     */
    public function makeContextHash(string $normalizedTitle, array $hashableContext): string
    {
        // Sort keys for consistent hashing
        ksort($hashableContext);
        $data = $normalizedTitle . '|' . json_encode($hashableContext, JSON_UNESCAPED_UNICODE);
        return md5($data);
    }

    /**
     * Создание fingerprint для набора этапов
     * 
     * Используется для идентификации уникальных наборов этапов
     * независимо от notes/комментариев.
     */
    public function makeFingerprint(array $steps): string
    {
        $fingerPrintData = [];
        
        foreach ($steps as $step) {
            // Берем только title (lower, trim) и hours (округлено до 0.1)
            $title = isset($step['title']) 
                ? mb_strtolower(trim($step['title']), 'UTF-8') 
                : '';
            $hours = isset($step['hours']) 
                ? round((float)$step['hours'], 1) 
                : 0.0;
            
            $fingerPrintData[] = [
                'title' => $title,
                'hours' => $hours,
            ];
        }
        
        return md5(json_encode($fingerPrintData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Полная нормализация данных для поиска/сохранения пресета
     */
    public function normalize(string $title, array $context): array
    {
        $normalizedTitle = $this->normalizeTitle($title);
        $hashableContext = $this->extractHashableContext($context);
        $contextHash = $this->makeContextHash($normalizedTitle, $hashableContext);
        
        return [
            'normalized_title' => $normalizedTitle,
            'hashable_context' => $hashableContext,
            'context_hash' => $contextHash,
        ];
    }
}
