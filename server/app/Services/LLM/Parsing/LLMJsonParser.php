<?php

declare(strict_types=1);

namespace App\Services\LLM\Parsing;

use App\Services\LLM\Exceptions\InvalidLLMJsonException;
use Illuminate\Support\Facades\Log;

/**
 * Парсер JSON ответов от LLM
 * 
 * Единый парсер для всех провайдеров.
 * Логика:
 * 1. json_decode(rawText)
 * 2. если не получилось — вырезать ```json ... ```
 * 3. если не получилось — от первого { до последнего }
 * 4. если не получилось — InvalidLLMJsonException
 * 
 * TODO: Self-repair (второй запрос "исправь JSON") — не в MVP
 */
class LLMJsonParser
{
    /**
     * Парсить JSON ответ от LLM
     * 
     * @param string $rawText Сырой текст ответа
     * @return array Распарсенный JSON
     * @throws InvalidLLMJsonException
     */
    public function parse(string $rawText): array
    {
        $content = trim($rawText);

        // Попытка 1: Прямой парсинг
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->validateStructure($decoded, $rawText);
        }

        // Попытка 2: Убрать markdown обертки ```json ... ```
        $cleaned = $this->stripMarkdownCodeBlocks($content);
        if ($cleaned !== $content) {
            $decoded = json_decode($cleaned, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validateStructure($decoded, $rawText);
            }
        }

        // Попытка 3: Извлечь от первого { до последнего }
        $extracted = $this->extractJsonObject($content);
        if ($extracted !== null) {
            $decoded = json_decode($extracted, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->validateStructure($decoded, $rawText);
            }
        }

        // Все попытки провалились
        Log::error('LLMJsonParser: Failed to parse JSON', [
            'error' => json_last_error_msg(),
            'content_preview' => substr($content, 0, 500),
        ]);

        throw new InvalidLLMJsonException(
            message: 'Failed to parse LLM response as JSON: ' . json_last_error_msg(),
            rawContent: $rawText
        );
    }

    /**
     * Парсить и нормализовать ответ декомпозиции
     * 
     * @param string $rawText Сырой текст ответа
     * @return array ['steps' => [...], 'totals' => ['hours' => float]]
     * @throws InvalidLLMJsonException
     */
    public function parseDecomposition(string $rawText): array
    {
        $decoded = $this->parse($rawText);

        // Валидация структуры
        if (!isset($decoded['steps']) || !is_array($decoded['steps'])) {
            throw new InvalidLLMJsonException(
                message: 'LLM response missing "steps" array',
                rawContent: $rawText
            );
        }

        // Нормализация шагов
        $validSteps = [];
        foreach ($decoded['steps'] as $i => $step) {
            if (empty($step) || !is_array($step)) {
                continue;
            }

            // Title обязателен
            if (empty($step['title'])) {
                Log::warning("LLMJsonParser: Step {$i} missing title, skipping");
                continue;
            }

            // Hours: если нет или невалидный — дефолт 0.25
            $hours = $step['hours'] ?? null;
            if (!is_numeric($hours) || $hours <= 0) {
                Log::warning("LLMJsonParser: Step {$i} invalid hours '{$hours}', defaulting to 0.25");
                $hours = 0.25;
            }

            // Basis: если нет — дефолт
            $basis = $step['basis'] ?? 'Практика предприятия';
            if (empty(trim($basis))) {
                $basis = 'Практика предприятия';
            }

            $validSteps[] = [
                'title' => trim($step['title']),
                'hours' => $this->normalizeHours((float) $hours),
                'basis' => trim($basis),
                'input_data' => isset($step['input_data']) ? trim($step['input_data']) : null,
            ];
        }

        if (empty($validSteps)) {
            throw new InvalidLLMJsonException(
                message: 'LLM response has no valid steps',
                rawContent: $rawText
            );
        }

        $totalHours = array_sum(array_column($validSteps, 'hours'));

        return [
            'steps' => $validSteps,
            'totals' => ['hours' => round($totalHours, 2)],
        ];
    }

    /**
     * Убрать markdown code blocks
     */
    private function stripMarkdownCodeBlocks(string $content): string
    {
        // ```json ... ``` или ``` ... ```
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        return trim($content);
    }

    /**
     * Извлечь JSON объект из текста
     */
    private function extractJsonObject(string $content): ?string
    {
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');

        if ($firstBrace === false || $lastBrace === false || $lastBrace <= $firstBrace) {
            return null;
        }

        return substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    /**
     * Валидация базовой структуры
     */
    private function validateStructure(array $decoded, string $rawText): array
    {
        // Должен быть хотя бы один ключ
        if (empty($decoded)) {
            throw new InvalidLLMJsonException(
                message: 'LLM response is empty JSON object',
                rawContent: $rawText
            );
        }

        return $decoded;
    }

    /**
     * Нормализовать часы (шаг 0.1)
     */
    private function normalizeHours(float $hours): float
    {
        return round($hours * 10) / 10;
    }
}
