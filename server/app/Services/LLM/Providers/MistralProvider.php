<?php

declare(strict_types=1);

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Parsing\LLMJsonParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Провайдер Mistral AI (включая Mixtral)
 * 
 * Использует Mistral API (OpenAI-совместимый формат).
 * Документация: https://docs.mistral.ai/api/
 */
class MistralProvider implements LLMProviderInterface
{
    private const NAME = 'mistral';
    private const DEFAULT_BASE_URL = 'https://api.mistral.ai/v1';
    private const DEFAULT_MODEL = 'mistral-small-latest';
    private const DEFAULT_TIMEOUT = 90;

    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private int $timeout;

    private LLMJsonParser $jsonParser;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?int $timeout = null,
        ?LLMJsonParser $jsonParser = null
    ) {
        $this->apiKey = $apiKey ?? config('services.mistral.key', '');
        $this->baseUrl = $baseUrl ?? config('services.mistral.base_url', self::DEFAULT_BASE_URL);
        $this->model = $model ?? config('services.mistral.model', self::DEFAULT_MODEL);
        $this->temperature = $temperature ?? (float) config('services.mistral.temperature', 0.2);
        $this->maxTokens = $maxTokens ?? (int) config('services.mistral.max_tokens', 4096);
        $this->timeout = $timeout ?? self::DEFAULT_TIMEOUT;
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
            timeout: isset($settings['timeout']) ? (int) $settings['timeout'] : null
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supportsJsonMode(): bool
    {
        // Mistral поддерживает response_format: json_object
        return true;
    }

    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])
            ->timeout(10)
            ->get($this->baseUrl . '/models');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('MistralProvider: ping failed', ['error' => $e->getMessage()]);
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
            ->timeout($this->timeout)
            ->post($this->baseUrl . '/chat/completions', $payload);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

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
            Log::error('MistralProvider: unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new LLMProviderException(
                message: "Unexpected error: {$e->getMessage()}",
                provider: self::NAME,
                errorType: 'unknown',
                previous: $e
            );
        }
    }

    /**
     * Примерная оценка стоимости
     * Mistral pricing (Jan 2025):
     * - mistral-small: $0.2/1M input, $0.6/1M output
     * - mistral-large: $2/1M input, $6/1M output
     */
    private function estimateCost(?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null || $completionTokens === null) {
            return null;
        }

        // Цены для mistral-small
        $inputCost = ($promptTokens / 1_000_000) * 0.2;
        $outputCost = ($completionTokens / 1_000_000) * 0.6;

        return round($inputCost + $outputCost, 6);
    }
}
