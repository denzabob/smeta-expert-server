<?php

declare(strict_types=1);

namespace App\Services\LLM\Providers;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Contracts\LLMStreamingProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\DTO\LLMStreamEvent;
use App\Services\LLM\DTO\LLMFileContent;
use App\Services\LLM\DTO\LLMImageContent;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMCapabilityCatalog;
use App\Services\LLM\OpenAiChatMessageMapper;
use App\Services\LLM\Parsing\LLMJsonParser;
use App\Services\LLM\Parsing\OpenAiSseStreamParser;
use App\Services\LLM\RouterAiFileAnnotationParser;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\BufferStream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;

/**
 * Провайдер RouterAI
 *
 * Использует OpenAI-совместимый API RouterAI.
 */
class RouterAiProvider implements LLMProviderInterface, LLMStreamingProviderInterface
{
    private const NAME = 'routerai';

    private const DEFAULT_BASE_URL = 'https://routerai.ru/api/v1';

    private const DEFAULT_MODEL = 'openai/gpt-4o';

    private const DEFAULT_TIMEOUT = 90;

    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private float $temperature;

    private int $maxTokens;

    private int $timeout;

    private int $connectTimeout;

    private LLMJsonParser $jsonParser;

    private ?ClientInterface $streamingClient;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?int $timeout = null,
        ?LLMJsonParser $jsonParser = null,
        ?int $connectTimeout = null,
        ?ClientInterface $streamingClient = null,
    ) {
        $this->apiKey = (string) ($apiKey ?? config('services.routerai.key') ?? '');
        $this->baseUrl = (string) ($baseUrl ?? config('services.routerai.base_url') ?? self::DEFAULT_BASE_URL);
        $this->model = (string) ($model ?? config('services.routerai.model') ?? self::DEFAULT_MODEL);
        $this->temperature = $temperature ?? (float) config('services.routerai.temperature', 0.2);
        $this->maxTokens = $maxTokens ?? (int) config('services.routerai.max_tokens', 4096);
        $this->timeout = $timeout ?? self::DEFAULT_TIMEOUT;
        $this->connectTimeout = $connectTimeout ?? (int) config('services.llm_transport.connect_timeout', 10);
        $this->jsonParser = $jsonParser ?? new LLMJsonParser;
        $this->streamingClient = $streamingClient;
    }

    /**
     * Создать провайдер из настроек.
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
        return true;
    }

    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout((int) config('services.llm_transport.health_timeout'))
                ->get($this->baseUrl.'/models');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::debug('RouterAiProvider: ping failed', ['exception' => $e::class]);

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
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', $payload);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if (! $response->successful()) {
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
                costUsd: null,
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
            Log::error('RouterAiProvider: unexpected error', [
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
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', array_filter([
                    'model' => $this->model,
                    'messages' => OpenAiChatMessageMapper::map($request),
                    'temperature' => $request->parameters['temperature'] ?? $this->temperature,
                    'max_tokens' => $request->parameters['max_tokens'] ?? $this->maxTokens,
                    'reasoning_effort' => $request->parameters['reasoning_effort'] ?? null,
                    'plugins' => $this->pdfParserPlugin($request),
                ], static fn (mixed $value): bool => $value !== null));

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            if (! $response->successful()) {
                throw LLMProviderException::httpError(self::NAME, $response->status(), $response->body());
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (! is_string($content) || trim($content) === '') {
                throw new LLMProviderException('Provider returned an empty chat response', self::NAME, 'invalid_response');
            }

            return new LLMChatResponse(
                provider: self::NAME,
                model: is_string($data['model'] ?? null) ? $data['model'] : $this->model,
                content: $content,
                latencyMs: $latencyMs,
                promptTokens: $this->nullableInt($data['usage']['prompt_tokens'] ?? null),
                completionTokens: $this->nullableInt($data['usage']['completion_tokens'] ?? null),
                totalTokens: $this->nullableInt($data['usage']['total_tokens'] ?? null),
                cachedTokens: $this->nullableInt($data['usage']['prompt_tokens_details']['cached_tokens'] ?? null),
                reasoningTokens: $this->nullableInt($data['usage']['completion_tokens_details']['reasoning_tokens'] ?? null),
                parsedFiles: RouterAiFileAnnotationParser::parse($data['choices'][0]['message']['annotations'] ?? []),
                metadata: array_filter([
                    'upstream_id' => is_string($data['id'] ?? null) ? $data['id'] : null,
                    'upstream_provider' => is_string($data['provider'] ?? null) ? $data['provider'] : null,
                    'service_tier' => is_string($data['service_tier'] ?? null) ? $data['service_tier'] : null,
                ], static fn (mixed $value): bool => $value !== null),
            );
        } catch (LLMProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                throw LLMProviderException::timeout(self::NAME, $this->timeout);
            }

            throw LLMProviderException::networkError(self::NAME, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('RouterAiProvider: unexpected chat error', ['exception' => $e::class]);

            throw new LLMProviderException(
                message: 'Unexpected chat provider error',
                provider: self::NAME,
                errorType: 'unknown',
            );
        }
    }

    /** @return iterable<LLMStreamEvent> */
    public function streamChat(LLMChatRequest $request, LLMCancellationToken $cancellationToken): iterable
    {
        if ($this->apiKey === '') {
            throw LLMProviderException::configError(self::NAME, 'API key is not configured');
        }

        $startedAt = microtime(true);
        $handler = $this->streamingClient === null ? new CurlMultiHandler(['select_timeout' => 0.25]) : null;
        $client = $this->streamingClient ?? new Client([
            'handler' => HandlerStack::create($handler),
            'connect_timeout' => $this->connectTimeout,
            // Only the handshake is bounded below; the SSE body stays streamed.
            'timeout' => 0,
            // Retained for injected stream clients; the cURL event loop below
            // enforces the same first-byte and idle limits itself.
            'read_timeout' => max(1, (float) min(
                (int) config('expert.streaming.time_to_first_token_seconds', 45),
                (int) config('expert.streaming.idle_timeout_seconds', 45),
            )),
            'http_errors' => false,
        ]);

        $plugin = $this->pdfParserPlugin($request);
        $payload = array_filter([
            'model' => $this->model,
            'messages' => OpenAiChatMessageMapper::map($request),
            'temperature' => $request->parameters['temperature'] ?? $this->temperature,
            'max_tokens' => $request->parameters['max_tokens'] ?? $this->maxTokens,
            'reasoning_effort' => $request->parameters['reasoning_effort'] ?? null,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'plugins' => $plugin,
        ], static fn (mixed $value): bool => $value !== null);
        [$fileCount, $rawFileBytes, $estimatedBase64Bytes] = $this->streamPayloadSizes($request);
        $context = [
            'correlation_id' => $cancellationToken->correlationId,
            'model' => $this->model,
            'has_images' => $request->hasImages(),
            'has_files' => $request->hasFiles(),
            'pdf_processing_intents' => $request->pdfProcessingIntents(),
            'pdf_engine' => $plugin[0]['pdf']['engine'] ?? null,
            'file_count' => $fileCount,
            'raw_file_bytes' => $rawFileBytes,
            'estimated_base64_payload_bytes' => $estimatedBase64Bytes,
        ];
        $handshakeSeconds = max(1, (int) config('expert.streaming.provider_handshake_timeout_seconds', 180));
        $sink = $handler === null ? null : new BufferStream(PHP_INT_MAX);
        $response = null;
        $requestFailure = null;
        $transferFinished = false;
        $promise = null;
        try {
            $cancellationToken->tick();
            if ($cancellationToken->isCancellationRequested()) {
                return;
            }
            Log::info('RouterAI stream request starting', [...$context, 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            $requestStartedAt = microtime(true);
            $promise = $client->requestAsync('POST', $this->baseUrl.'/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ],
                'json' => $payload,
                'stream' => true,
                ...($sink === null ? [] : [
                    'sink' => $sink,
                    'on_headers' => static function (ResponseInterface $headers) use (&$response): void {
                        if ($headers->getStatusCode() >= 200) {
                            $response = $headers;
                        }
                    },
                ]),
            ]);
            $promise->then(
                static function (ResponseInterface $received) use (&$response, &$transferFinished): void { $response = $received; $transferFinished = true; },
                static function (mixed $reason) use (&$requestFailure, &$transferFinished): void { $requestFailure = $reason; $transferFinished = true; },
            );
            while ($response === null && $requestFailure === null) {
                $cancellationToken->tick();
                if ($cancellationToken->isCancellationRequested()) {
                    $promise->cancel();
                    Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'cancelled', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);

                    return;
                }
                if (microtime(true) - $requestStartedAt >= $handshakeSeconds) {
                    $promise->cancel();
                    throw LLMProviderException::timeout(self::NAME, $handshakeSeconds);
                }
                if ($handler !== null) {
                    $handler->tick();
                } else {
                    usleep(100_000);
                }
                Utils::queue()->run();
            }
            if ($requestFailure !== null) {
                throw $requestFailure instanceof \Throwable ? $requestFailure : new \RuntimeException('Streaming provider request failed');
            }
            Log::info('RouterAI stream response headers received', [
                'correlation_id' => $cancellationToken->correlationId,
                'model' => $this->model,
                'http_status' => $response->getStatusCode(),
                'content_type' => mb_substr($response->getHeaderLine('Content-Type'), 0, 100),
                'elapsed_ms' => $this->streamElapsedMs($startedAt),
            ]);
        } catch (LLMProviderException $exception) {
            $promise?->cancel();
            Log::warning('RouterAI stream request failed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'exception_class' => $exception::class, 'error_code' => $exception->getErrorType() === 'timeout' ? 'provider_timeout' : 'provider_connection_failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            throw $exception;
        } catch (ConnectException $exception) {
            $promise?->cancel();
            $timedOut = str_contains(strtolower($exception->getMessage()), 'timed out');
            Log::warning('RouterAI stream request failed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'exception_class' => $exception::class, 'error_code' => $timedOut ? 'provider_timeout' : 'provider_connection_failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            if ($timedOut) {
                throw LLMProviderException::timeout(self::NAME, $handshakeSeconds);
            }
            throw LLMProviderException::networkError(self::NAME, $exception->getMessage());
        } catch (\Throwable $exception) {
            $promise?->cancel();
            Log::warning('RouterAI stream request failed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'exception_class' => $exception::class, 'error_code' => 'provider_connection_failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
            throw new LLMProviderException('Streaming provider connection failed', self::NAME, 'network');
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $promise?->cancel();
            $body = $sink ?? $response->getBody();
            try {
                $body->close();
            } finally {
                Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
                throw LLMProviderException::httpError(self::NAME, $response->getStatusCode(), 'Streaming provider returned an HTTP error');
            }
        }

        if (! $this->isSseContentType($response->getHeaderLine('Content-Type'))) {
            $promise?->cancel();
            $body = $sink ?? $response->getBody();
            try {
                $body->close();
            } finally {
                Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => 'failed', 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
                throw new LLMProviderException(
                    'Streaming provider returned an unexpected content type',
                    self::NAME,
                    'unexpected_content_type',
                );
            }
        }

        $body = $sink ?? $response->getBody();

        $completed = false;
        $firstEvent = true;
        $firstFrameLogged = false;
        $frameTail = '';
        $lastBodyByteAt = microtime(true);
        try {
            $chunks = (function () use ($body, $cancellationToken, $handler, $promise, $startedAt, &$transferFinished, &$requestFailure, &$firstEvent, &$firstFrameLogged, &$frameTail, &$lastBodyByteAt): iterable {
                while (! $body->eof() || ($handler !== null && ! $transferFinished)) {
                    $cancellationToken->tick();
                    if ($cancellationToken->isCancellationRequested()) {
                        $promise?->cancel();
                        $body->close(); // Closes the active upstream socket, not only UI rendering.

                        return;
                    }
                    if ($handler !== null && $body->eof()) {
                        $waitSeconds = max(1, (int) config($firstEvent ? 'expert.streaming.time_to_first_token_seconds' : 'expert.streaming.idle_timeout_seconds', 45));
                        if (microtime(true) - $lastBodyByteAt >= $waitSeconds) {
                            throw LLMProviderException::timeout(self::NAME, $waitSeconds);
                        }
                        $handler->tick();
                        Utils::queue()->run();
                        continue;
                    }
                    $chunk = $body->read(8192);
                    if ($chunk !== '') {
                        $lastBodyByteAt = microtime(true);
                        if (! $firstFrameLogged) {
                            $probe = $frameTail.$chunk;
                            if (preg_match('/\r?\n\r?\n/', $probe) === 1) {
                                $firstFrameLogged = true;
                                Log::info('RouterAI stream first event', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
                            } else {
                                $frameTail = substr($probe, -3);
                            }
                        }
                    }
                    $cancellationToken->tick();
                    yield $chunk;
                }
                if ($requestFailure !== null) {
                    throw $requestFailure instanceof \Throwable ? $requestFailure : new \RuntimeException('Streaming provider body failed');
                }
            })();
            // Disabled by default: enable only after the selected RouterAI
            // integration has a documented, dedicated safe-summary field.
            foreach ((new OpenAiSseStreamParser((bool) config('services.routerai.safe_reasoning_summary_supported', false)))->parse($chunks, $cancellationToken) as $event) {
                if ($firstEvent) {
                    $firstEvent = false;
                }
                if ($event->type === 'done') {
                    $completed = true;
                }
                yield $event;
            }
        } catch (LLMProviderException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            if ($cancellationToken->isCancellationRequested()) {
                return;
            }
            throw LLMProviderException::networkError(self::NAME, $exception->getMessage());
        } finally {
            if (! $transferFinished) {
                $promise?->cancel();
            }
            $body->close();
            Log::info('RouterAI stream completed', ['correlation_id' => $cancellationToken->correlationId, 'model' => $this->model, 'status' => $completed ? 'completed' : ($cancellationToken->isCancellationRequested() ? 'cancelled' : 'failed'), 'elapsed_ms' => $this->streamElapsedMs($startedAt)]);
        }
    }

    /** @return array{int, int, int} */
    private function streamPayloadSizes(LLMChatRequest $request): array
    {
        $fileCount = 0;
        $rawFileBytes = 0;
        $base64Bytes = 0;
        foreach ($request->messages as $message) {
            $blocks = $message instanceof LLMChatMessage ? $message->content : (is_array($message) ? ($message['content'] ?? []) : []);
            foreach (is_array($blocks) ? $blocks : [] as $block) {
                if ($block instanceof LLMFileContent) {
                    $fileCount++;
                    $rawFileBytes += strlen($block->bytes);
                }
                if ($block instanceof LLMFileContent || $block instanceof LLMImageContent) {
                    $base64Bytes += 4 * (int) ceil(strlen($block->bytes) / 3);
                }
            }
        }

        return [$fileCount, $rawFileBytes, $base64Bytes];
    }

    private function streamElapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? (int) $value
            : null;
    }

    /** @return array{0: array{id: string, pdf: array{engine: string}}}|null */
    private function pdfParserPlugin(LLMChatRequest $request): ?array
    {
        $intents = $request->pdfProcessingIntents();
        if ($intents === []) {
            return null;
        }

        // A mixed request uses the OCR-capable path deliberately. This is the
        // conservative RouterAI contract until per-file parser settings are
        // verified and supported by the upstream API.
        $hasOcr = $request->hasPdfOcrFiles();
        $engine = $hasOcr
            ? (string) config('expert.pdf_processing.ocr_engine', config('expert.pdf_ocr.engine', 'mistral-ocr'))
            : (string) config('expert.pdf_processing.text_engine', 'cloudflare-ai');

        Log::info('RouterAI PDF parser selected.', [
            'processing_intents' => $intents,
            'selected_engine' => $engine,
            'ocr_priority' => $hasOcr,
        ]);

        return [['id' => 'file-parser', 'pdf' => ['engine' => $engine]]];
    }

    private function isSseContentType(string $contentType): bool
    {
        return strtolower(trim(strtok($contentType, ';'))) === 'text/event-stream';
    }
}
