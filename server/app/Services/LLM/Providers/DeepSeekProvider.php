<?php

declare(strict_types=1);

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMCapabilityCatalog;
use App\Services\LLM\OpenAiChatMessageMapper;
use App\Services\LLM\Parsing\LLMJsonParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Провайдер DeepSeek
 * 
 * Использует DeepSeek API (OpenAI-совместимый формат).
 * Документация: https://platform.deepseek.com/api-docs
 */
class DeepSeekProvider implements LLMProviderInterface
{
    private const NAME = 'deepseek';
    private const DEFAULT_BASE_URL = 'https://api.deepseek.com/v1';
    private const DEFAULT_MODEL = 'deepseek-chat';
    private const DEFAULT_TIMEOUT = 90;

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private int $timeout;
    private int $connectTimeout;

    private LLMJsonParser $jsonParser;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?int $timeout = null,
        ?LLMJsonParser $jsonParser = null,
        ?int $connectTimeout = null,
    ) {
        $this->apiKey = (string) ($apiKey ?? config('services.deepseek.key') ?? '');
        $this->baseUrl = (string) ($baseUrl ?? config('services.deepseek.base_url') ?? self::DEFAULT_BASE_URL);
        $this->model = (string) ($model ?? config('services.deepseek.model') ?? self::DEFAULT_MODEL);
        $this->temperature = $temperature ?? (float) config('services.deepseek.temperature', 0.2);
        $this->maxTokens = $maxTokens ?? (int) config('services.deepseek.max_tokens', 4096);
        $this->timeout = $timeout ?? self::DEFAULT_TIMEOUT;
        $this->connectTimeout = $connectTimeout ?? (int) config('services.llm_transport.connect_timeout', 10);
        $this->jsonParser = $jsonParser ?? new LLMJsonParser();
    }

    /**
     * Создать провайдер из настроек
     */
    public static function fromSettings(array $settings): self
    {
        return new self(
            apiKey: $settings['api_key'] ?? null,
            baseUrl: $settings['base_url'] ?? null,
            model: $settings['model'] ?? null,
            temperature: isset($settings['temperature']) ? (float) $settings['temperature'] : null,
            maxTokens: isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : null,
            timeout: isset($settings['timeout']) ? (int) $settings['timeout'] : null,
            connectTimeout: isset($settings['connect_timeout']) ? (int) $settings['connect_timeout'] : null,
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function capabilities(): array
    {
        return LLMCapabilityCatalog::forProviderModel(self::NAME, $this->model);
    }

    public function supportsJsonMode(): bool
    {
        // DeepSeek поддерживает response_format: json_object
        return true;
    }

    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            // Простой ping-запрос к models endpoint
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout((int) config('services.llm_transport.health_timeout'))
            ->get($this->baseUrl . '/models');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('DeepSeekProvider: ping failed', ['exception' => $e::class]);
            return false;
        }
    }

    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse
    {
        if (empty($this->apiKey)) {
            throw LLMProviderException::configError(self::NAME, 'API key is not configured');
        }

        $startTime = microtime(true);

        try {
            // DeepSeek использует OpenAI-совместимый формат
            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $prompt->systemPrompt],
                    ['role' => 'user', 'content' => $prompt->userPrompt],
                ],
                'temperature' => $this->temperature,
                'max_tokens' => $this->maxTokens,
                'response_format' => ['type' => 'json_object'],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            ->post($this->baseUrl . '/chat/completions', $payload);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            // Обработка HTTP ошибок
            if (!$response->successful()) {
                throw LLMProviderException::httpError(
                    self::NAME,
                    $response->status(),
                    $response->body()
                );
            }

            $data = $response->json();
            $rawText = $data['choices'][0]['message']['content'] ?? '';
            $promptTokens = $data['usage']['prompt_tokens'] ?? null;
            $completionTokens = $data['usage']['completion_tokens'] ?? null;

            // Парсинг JSON
            $parsed = $this->jsonParser->parseDecomposition($rawText);

            return new LLMResponse(
                provider: self::NAME,
                model: $this->model,
                rawText: $rawText,
                json: $parsed,
                latencyMs: $latencyMs,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costUsd: $this->estimateCost($promptTokens, $completionTokens),
                usedJsonMode: true
            );

        } catch (LLMProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                throw LLMProviderException::timeout(self::NAME, $this->timeout);
            }

            throw LLMProviderException::networkError(self::NAME, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('DeepSeekProvider: unexpected error', [
                'exception' => $e::class,
            ]);

            throw new LLMProviderException(
                message: 'Unexpected provider error',
                provider: self::NAME,
                errorType: 'unknown',
            );
        }
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        if (empty($this->apiKey)) {
            throw LLMProviderException::configError(self::NAME, 'API key is not configured');
        }

        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->baseUrl . '/chat/completions', array_filter([
                    'model' => $this->model,
                    'messages' => OpenAiChatMessageMapper::map($request),
                    'temperature' => $request->parameters['temperature'] ?? $this->temperature,
                    'max_tokens' => $request->parameters['max_tokens'] ?? $this->maxTokens,
                    'reasoning_effort' => $request->parameters['reasoning_effort'] ?? null,
                ], static fn (mixed $value): bool => $value !== null));

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if (!$response->successful()) {
                throw LLMProviderException::httpError(self::NAME, $response->status(), $response->body());
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                throw new LLMProviderException('Provider returned an empty chat response', self::NAME, 'invalid_response');
            }

            return new LLMChatResponse(
                provider: self::NAME,
                model: $this->model,
                content: $content,
                latencyMs: $latencyMs,
                promptTokens: $data['usage']['prompt_tokens'] ?? null,
                completionTokens: $data['usage']['completion_tokens'] ?? null,
                costUsd: $this->estimateCost($data['usage']['prompt_tokens'] ?? null, $data['usage']['completion_tokens'] ?? null),
            );
        } catch (LLMProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                throw LLMProviderException::timeout(self::NAME, $this->timeout);
            }

            throw LLMProviderException::networkError(self::NAME, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('DeepSeekProvider: unexpected chat error', ['exception' => $e::class]);

            throw new LLMProviderException(
                message: 'Unexpected chat provider error',
                provider: self::NAME,
                errorType: 'unknown',
            );
        }
    }

    /**
     * Примерная оценка стоимости
     * DeepSeek pricing (Jan 2025):
     * - deepseek-chat: $0.14/1M input, $0.28/1M output (cache miss)
     * - deepseek-reasoner: $0.55/1M input, $2.19/1M output
     */
    private function estimateCost(?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null || $completionTokens === null) {
            return null;
        }

        // Цены для deepseek-chat
        $inputCost = ($promptTokens / 1_000_000) * 0.14;
        $outputCost = ($completionTokens / 1_000_000) * 0.28;

        return round($inputCost + $outputCost, 6);
    }
}
