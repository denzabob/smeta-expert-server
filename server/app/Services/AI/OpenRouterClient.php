<?php

namespace App\Services\AI;

use App\Models\AiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Клиент для OpenRouter API
 */
class OpenRouterClient
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private float $temperature;
    private int $maxTokens;

    /**
     * Системный промпт для генерации этапов работ
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
Ты — эксперт по нормированию труда в сфере мебели и ремонта. Твоя задача — разбить работу на конкретные этапы.

СТРОГИЕ ПРАВИЛА:
1. Отвечай ТОЛЬКО валидным JSON, без комментариев, без markdown
2. Генерируй от 3 до 10 этапов
3. Каждый этап должен быть конкретным действием в инфинитиве (что делать)
4. Часы указывай с шагом 0.25 (0.25, 0.5, 0.75, 1.0, 1.25...)
5. Basis (основание) обязателен для каждого этапа
6. Не включай примечания и комментарии

ФОРМАТ ОТВЕТА:
{
  "steps": [
    {
      "title": "Отключить и демонтировать встраиваемую технику",
      "hours": 0.5,
      "basis": "СП 76.13330.2016",
      "input_data": "2 шт."
    }
  ]
}

ПРИМЕРЫ BASIS:
- ГЭСНр 69-1-1 (для демонтажа)
- СП 76.13330.2016 (для монтажа мебели)
- Нормы времени на сборку корпусной мебели
- Практика предприятия
- Расчет от объема

ВАЖНО:
- Суммарное время должно соответствовать сложности работы
- Если указано желаемое время - подгони сумму под него
- Учитывай контекст (этаж, наличие техники, состояние помещения)
PROMPT;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.key', '');
        $this->model = config('services.openrouter.model', 'google/gemini-2.0-flash-001');
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->temperature = (float) config('services.openrouter.temperature', 0.2);
        $this->maxTokens = (int) config('services.openrouter.max_tokens', 4096);
    }

    /**
     * Сгенерировать этапы работы
     * 
     * @param string $title Название работы
     * @param array $context Контекст работы
     * @param float|null $desiredHours Желаемое количество часов
     * @return array ['steps' => [...], 'tokens' => [...]]
     * @throws \Exception
     */
    public function generateSteps(string $title, array $context, ?float $desiredHours = null): array
    {
        if (empty($this->apiKey)) {
            throw new \Exception('OpenRouter API key is not configured');
        }

        $userPrompt = $this->buildUserPrompt($title, $context, $desiredHours);
        $inputHash = md5($userPrompt);
        
        $startTime = microtime(true);
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://smeta.expert'),
                'X-Title' => 'ПРИЗМА',
            ])
            ->timeout(60)
            ->post($this->baseUrl . '/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens,
                'response_format' => ['type' => 'json_object'],
            ]);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            
            if (!$response->successful()) {
                $this->logRequest($inputHash, $latencyMs, false, null, null, $response->body());
                throw new \Exception('OpenRouter API error: ' . $response->status() . ' - ' . $response->body());
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            
            // Извлекаем информацию о токенах
            $promptTokens = $data['usage']['prompt_tokens'] ?? null;
            $completionTokens = $data['usage']['completion_tokens'] ?? null;
            
            // Парсим JSON ответ
            $parsed = $this->parseJsonResponse($content);
            
            // Логируем успешный запрос
            $this->logRequest($inputHash, $latencyMs, true, $promptTokens, $completionTokens);
            
            return [
                'steps' => $parsed['steps'] ?? [],
                'tokens' => [
                    'prompt' => $promptTokens,
                    'completion' => $completionTokens,
                ],
            ];
            
        } catch (\Exception $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->logRequest($inputHash, $latencyMs, false, null, null, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Построить пользовательский промпт
     */
    private function buildUserPrompt(string $title, array $context, ?float $desiredHours): string
    {
        $prompt = "Работа: {$title}\n\n";
        
        if (!empty($context)) {
            $prompt .= "Контекст:\n";
            foreach ($context as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $prompt .= "- {$key}: {$value}\n";
            }
            $prompt .= "\n";
        }
        
        if ($desiredHours !== null) {
            $prompt .= "Желаемое общее время: {$desiredHours} часов\n";
            $prompt .= "Подгони сумму часов всех этапов под это значение.\n";
        }
        
        $prompt .= "\nСгенерируй этапы работы в формате JSON.";
        
        return $prompt;
    }

    /**
     * Парсинг JSON ответа от AI
     */
    private function parseJsonResponse(string $content): array
    {
        // Убираем возможные markdown обертки
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        $content = trim($content);
        
        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('OpenRouter JSON parse error', [
                'error' => json_last_error_msg(),
                'content' => substr($content, 0, 500),
            ]);
            throw new \Exception('Failed to parse AI response as JSON: ' . json_last_error_msg());
        }
        
        // Валидация структуры
        if (!isset($decoded['steps']) || !is_array($decoded['steps'])) {
            throw new \Exception('AI response missing "steps" array');
        }
        
        // Валидация и нормализация каждого шага
        $validSteps = [];
        foreach ($decoded['steps'] as $i => $step) {
            // Пропускаем пустые шаги
            if (empty($step) || !is_array($step)) {
                continue;
            }
            
            // Title обязателен
            if (empty($step['title'])) {
                Log::warning("Step {$i}: missing title, skipping");
                continue;
            }
            
            // Hours: если нет или невалидный - ставим дефолт 0.25
            $hours = $step['hours'] ?? null;
            if (!is_numeric($hours) || $hours <= 0) {
                Log::warning("Step {$i}: invalid hours '{$hours}', defaulting to 0.25");
                $hours = 0.25;
            }
            
            // Basis: если нет - ставим дефолт
            $basis = $step['basis'] ?? 'Практика предприятия';
            if (empty(trim($basis))) {
                $basis = 'Практика предприятия';
            }
            
            $validSteps[] = [
                'title' => trim($step['title']),
                'hours' => (float) $hours,
                'basis' => trim($basis),
                'input_data' => isset($step['input_data']) ? trim($step['input_data']) : null,
            ];
        }
        
        if (empty($validSteps)) {
            throw new \Exception('AI response has no valid steps');
        }
        
        // Пересчитываем totals
        $totalHours = array_sum(array_column($validSteps, 'hours'));
        
        return [
            'steps' => $validSteps,
            'totals' => ['hours' => round($totalHours, 2)],
        ];
    }

    /**
     * Логирование запроса в БД
     */
    private function logRequest(
        string $inputHash,
        int $latencyMs,
        bool $isSuccessful,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?string $errorMessage = null
    ): void {
        try {
            AiLog::logRequest(
                $inputHash,
                $this->model,
                $latencyMs,
                $isSuccessful,
                $promptTokens,
                $completionTokens,
                null, // cost_usd - можно добавить расчет позже
                $errorMessage
            );
        } catch (\Exception $e) {
            Log::warning('Failed to log AI request', ['error' => $e->getMessage()]);
        }
    }
}
