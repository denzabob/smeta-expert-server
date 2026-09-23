<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Contracts\LLMStreamingProviderInterface;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMProfileFallbackSelection;
use App\Services\LLM\DTO\LLMStreamEvent;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Enums\LLMErrorType;
use App\Services\LLM\Exceptions\InvalidLLMJsonException;
use App\Services\LLM\Exceptions\LLMChatUnavailableException;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Exceptions\LLMUnavailableException;
use App\Services\LLM\Exceptions\LLMUnsupportedCapabilityException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Роутер LLM запросов с поддержкой failover, retry и structured logging.
 *
 * Поведение детерминировано:
 *   1. Строится execution plan: [primary, ...fallback]
 *   2. Для каждого провайдера:
 *      — skip если circuit breaker OPEN
 *      — skip если не сконфигурирован
 *      — отправить запрос (+ retry для retryable ошибок)
 *      — при auth/config → fail-fast, без retry
 *   3. Если все провайдеры провалились → LLMUnavailableException
 *
 * Режимы:
 *   manual — только primary, без failover
 *   auto   — primary + fallback цепочка
 */
class LLMRouter
{
    private const MAX_ATTEMPTS_PER_PROVIDER = 2;
    private const MAX_JSON_RETRY_ATTEMPTS = 1;
    private const RETRY_BASE_DELAY_MS = 200;
    private const RETRY_JITTER_MAX_MS = 100;

    private CircuitBreaker $circuitBreaker;
    private LLMSettingsRepository $settings;
    private LLMErrorClassifier $errorClassifier;

    private ?int $currentUserId = null;
    private ?string $lastCorrelationId = null;
    private ?LLMTaskProfileResolver $taskProfiles;

    /** @var array<string, LLMProviderInterface> */
    private array $providerInstances = [];

    /** @var array<string, true> */
    private array $capabilityRefreshAttempts = [];

    /** @var (\Closure(string, array): ?LLMProviderInterface)|null */
    private ?\Closure $providerFactory;

    public function __construct(
        CircuitBreaker $circuitBreaker,
        LLMSettingsRepository $settings,
        LLMErrorClassifier $errorClassifier,
        ?\Closure $providerFactory = null,
        ?LLMTaskProfileResolver $taskProfiles = null,
    ) {
        $this->circuitBreaker = $circuitBreaker;
        $this->settings = $settings;
        $this->errorClassifier = $errorClassifier;
        $this->providerFactory = $providerFactory;
        $this->taskProfiles = $taskProfiles;
    }

    /**
     * Установить user_id для текущего запроса.
     */
    public function setUserId(?int $userId): self
    {
        $this->currentUserId = $userId;
        return $this;
    }

    /**
     * Получить correlation_id последнего запроса.
     */
    public function getLastCorrelationId(): ?string
    {
        return $this->lastCorrelationId;
    }

    /**
     * Сгенерировать декомпозицию через LLM.
     *
     * @throws LLMUnavailableException
     */
    public function generateDecomposition(DecompositionPrompt $prompt, ?string $correlationId = null): LLMResponse
    {
        $logger = new LLMLogger($correlationId);
        $this->lastCorrelationId = $logger->getCorrelationId();
        $executionPlan = $this->buildExecutionPlan();

        $failoverChain = [];
        $jsonRetryCount = 0;
        $attemptIndex = 0;

        Log::info('LLMRouter: starting request', [
            'correlation_id' => $logger->getCorrelationId(),
            'execution_plan' => $executionPlan,
        ]);

        foreach ($executionPlan as $providerName) {
            // --- gate: circuit breaker ---
            if (!$this->circuitBreaker->isAvailable($providerName)) {
                $failoverChain[] = "{$providerName}:circuit_open";
                Log::info("LLMRouter: skip {$providerName} (circuit open)");
                continue;
            }

            // --- gate: configured ---
            $provider = $this->getProvider($providerName);
            if ($provider === null) {
                $failoverChain[] = "{$providerName}:not_configured";
                Log::warning("LLMRouter: skip {$providerName} (not configured)");
                continue;
            }

            // --- try with retry ---
            $retryCount = 0;
            $maxAttempts = self::MAX_ATTEMPTS_PER_PROVIDER;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $startTime = microtime(true);

                try {
                    $response = $provider->generateDecomposition($prompt);

                    // Success
                    $this->circuitBreaker->recordSuccess($providerName);

                    $logger->logFinal(
                        prompt: $prompt,
                        response: $response,
                        failoverChain: $failoverChain,
                        executionPlan: $executionPlan,
                        attemptIndex: $attemptIndex,
                        retryCount: $retryCount,
                        userId: $this->currentUserId,
                    );

                    return $response;

                } catch (InvalidLLMJsonException $e) {
                    $jsonRetryCount++;
                    $failoverChain[] = "{$providerName}:invalid_json";
                    $latency = (int) ((microtime(true) - $startTime) * 1000);

                    Log::warning("LLMRouter: {$providerName} invalid JSON", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'retry' => $jsonRetryCount,
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: LLMErrorType::INVALID_RESPONSE,
                        errorMessage: $e->getMessage(),
                        httpStatus: null,
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    if ($jsonRetryCount > self::MAX_JSON_RETRY_ATTEMPTS) {
                        Log::error("LLMRouter: max JSON retries reached");
                        break 2; // выход из обоих циклов
                    }

                    // JSON retry — не считаем circuit failure
                    break; // next provider

                } catch (LLMProviderException $e) {
                    $errorType = $this->errorClassifier->classify($e, $e->getHttpStatus());
                    $latency = (int) ((microtime(true) - $startTime) * 1000);
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::warning("LLMRouter: {$providerName} failed", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'error_type' => $errorType->value,
                        'http_status' => $e->getHttpStatus(),
                        'attempt' => $attempt + 1,
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: $errorType,
                        errorMessage: $e->getMessage(),
                        httpStatus: $e->getHttpStatus(),
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    // Fail-fast для auth/config
                    if (!$errorType->isFailoverAllowed()) {
                        Log::critical("LLMRouter: {$providerName} config/auth error — no failover");

                        if ($this->settings->getMode() === 'manual') {
                            $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                                "Provider {$providerName} configuration error: {$e->getMessage()}");
                        }
                        break; // перейти к следующему провайдеру
                    }

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    // Retry для retryable ошибок
                    if ($errorType->isRetryable() && $attempt + 1 < $maxAttempts) {
                        $retryCount++;
                        $delayMs = (int) (self::RETRY_BASE_DELAY_MS * (2 ** $attempt) + random_int(0, self::RETRY_JITTER_MAX_MS));
                        Log::info("LLMRouter: retrying {$providerName} (attempt " . ($attempt + 2) . ") after {$delayMs}ms");
                        usleep($delayMs * 1000);
                        continue; // повторить цикл for
                    }

                    // В manual — не делаем failover на следующий провайдер
                    if ($this->settings->getMode() === 'manual') {
                        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                            'AI unavailable (manual mode, no failover)');
                    }

                    break; // следующий провайдер

                } catch (\Throwable $e) {
                    $errorType = $this->errorClassifier->classify($e);
                    $latency = (int) ((microtime(true) - $startTime) * 1000);
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::error("LLMRouter: {$providerName} unexpected error", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'error' => $e->getMessage(),
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: $errorType,
                        errorMessage: $e->getMessage(),
                        httpStatus: null,
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    if ($this->settings->getMode() === 'manual') {
                        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                            "AI unavailable: {$e->getMessage()}");
                    }

                    break; // следующий провайдер
                }
            }

            $attemptIndex++;
        }

        // Все провайдеры недоступны
        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
            'All LLM providers are unavailable');
    }

    /**
     * Выполнить обычный text-chat запрос через тот же provider/failover routing,
     * но без JSON mode и decomposition parser.
     *
     * @throws LLMChatUnavailableException
     */
    public function chat(LLMChatRequest $request, ?string $correlationId = null, ?string $taskProfile = null, ?LLMProfileFallbackSelection $profileFallback = null): LLMChatResponse
    {
        $this->lastCorrelationId = $correlationId ?? (string) Str::uuid();
        $this->capabilityRefreshAttempts = [];
        $executionPlan = $this->buildExecutionPlan($taskProfile);
        $profile = $this->activeProfile($taskProfile);
        $this->assertProfileFallbackSelection($profile, $profileFallback);
        if ($profileFallback !== null) {
            $executionPlan = [$profileFallback->provider];
        }
        $failoverChain = [];
        $attemptIndex = 0;
        $lastErrorType = null;

        if ($request->hasFiles()) {
            if (! $this->profileAllowsFallback($profile)) {
                $executionPlan = array_slice($executionPlan, 0, 1);
            }
            $providerName = $executionPlan[0] ?? $this->settings->getPrimaryProvider();
            $provider = $this->getProvider($providerName, $profileFallback?->model ?? $this->profileModel($taskProfile, $providerName));
            $requiredCapability = $request->hasPdfOcrFiles() ? LLMCapability::PDF_OCR : LLMCapability::FILE_INPUT;
            if (! $this->profileAllowsFallback($profile) && ($provider === null || ! $this->supportsMediaRequest($provider, $requiredCapability, $profile, $taskProfile, $request))) {
                throw new LLMUnsupportedCapabilityException($provider?->name() ?? $providerName, $provider?->model() ?? 'unknown', $requiredCapability);
            }
        }

        if ($request->hasImages()) {
            if (! $this->profileAllowsFallback($profile)) {
                $executionPlan = array_slice($executionPlan, 0, 1);
            }
            $providerName = $executionPlan[0] ?? $this->settings->getPrimaryProvider();
            $provider = $this->getProvider($providerName, $profileFallback?->model ?? $this->profileModel($taskProfile, $providerName));

            if (! $this->profileAllowsFallback($profile) && $provider !== null && ! $this->supportsMediaRequest($provider, LLMCapability::IMAGE_INPUT, $profile, $taskProfile, $request)) {
                Log::warning('LLMRouter: configured profile rejected image input.', [
                    'correlation_id' => $this->lastCorrelationId,
                    'provider' => $provider->name(),
                    'model' => $provider->model(),
                    'capability' => LLMCapability::IMAGE_INPUT->value,
                ]);

                throw new LLMUnsupportedCapabilityException(
                    $provider->name(), $provider->model(), LLMCapability::IMAGE_INPUT,
                );
            }
        }

        Log::info('LLMRouter: starting text chat request', [
            'correlation_id' => $this->lastCorrelationId,
            'execution_plan' => $executionPlan,
        ]);

        foreach ($executionPlan as $providerIndex => $providerName) {
            if (!$this->circuitBreaker->isAvailable($providerName)) {
                $failoverChain[] = "{$providerName}:circuit_open";
                continue;
            }

            $provider = $this->getProvider($providerName, $profileFallback?->model ?? $this->profileModel($taskProfile, $providerName));
            if ($provider === null) {
                $failoverChain[] = "{$providerName}:not_configured";
                $lastErrorType = LLMErrorType::CONFIG;
                continue;
            }

            if ($request->hasImages() && ! $this->supportsMediaRequest($provider, LLMCapability::IMAGE_INPUT, $profile, $taskProfile, $request)) {
                $failoverChain[] = "{$providerName}:capability_mismatch";
                if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                    continue;
                }
                throw new LLMUnsupportedCapabilityException($provider->name(), $provider->model(), LLMCapability::IMAGE_INPUT);
            }
            if ($request->hasFiles()) {
                $requiredCapability = $request->hasPdfOcrFiles() ? LLMCapability::PDF_OCR : LLMCapability::FILE_INPUT;
                if (! $this->supportsMediaRequest($provider, $requiredCapability, $profile, $taskProfile, $request)) {
                    $failoverChain[] = "{$providerName}:capability_mismatch";
                    if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                        continue;
                    }
                    throw new LLMUnsupportedCapabilityException($provider->name(), $provider->model(), $requiredCapability);
                }
            }

            $retryCount = 0;

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_PROVIDER; $attempt++) {
                try {
                    $response = $provider->chat($this->requestForProvider($request, $profile, $provider));
                    $this->circuitBreaker->recordSuccess($providerName);
                    $this->logMediaCompletion($request, $taskProfile, $provider, $response->metadata['upstream_provider'] ?? $response->provider, $response->model);

                    Log::info('LLMRouter: text chat completed', [
                        'correlation_id' => $this->lastCorrelationId,
                        'provider' => $providerName,
                        'model' => $response->model,
                        'attempt_index' => $attemptIndex,
                        'retry_count' => $retryCount,
                    ]);

                    return $this->withFallbackMetadata($response, $profileFallback !== null || $providerIndex > 0, $profileFallback !== null ? 'capability_mismatch' : $this->fallbackReason($failoverChain));
                } catch (LLMProviderException $e) {
                    $errorType = $this->errorClassifier->classify($e, $e->getHttpStatus());
                    $lastErrorType = $errorType;
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::warning('LLMRouter: text chat provider failed', [
                        'correlation_id' => $this->lastCorrelationId,
                        'provider' => $providerName,
                        'error_type' => $errorType->value,
                        'http_status' => $e->getHttpStatus(),
                        'attempt' => $attempt + 1,
                    ]);

                    if (!$errorType->isFailoverAllowed()) {
                        if ($this->settings->getMode() === 'manual') {
                            $this->throwChatUnavailable($failoverChain, $lastErrorType,
                                "Provider {$providerName} configuration error");
                        }
                        break;
                    }

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    if ($errorType->isRetryable() && $attempt + 1 < self::MAX_ATTEMPTS_PER_PROVIDER) {
                        $retryCount++;
                        $delayMs = (int) (self::RETRY_BASE_DELAY_MS * (2 ** $attempt) + random_int(0, self::RETRY_JITTER_MAX_MS));
                        usleep($delayMs * 1000);
                        continue;
                    }

                    if ($this->settings->getMode() === 'manual') {
                        $this->throwChatUnavailable($failoverChain, $lastErrorType,
                            'AI unavailable (manual mode, no failover)');
                    }

                    break;
                } catch (\Throwable $e) {
                    $errorType = $this->errorClassifier->classify($e);
                    $lastErrorType = $errorType;
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::error('LLMRouter: unexpected text chat provider error', [
                        'correlation_id' => $this->lastCorrelationId,
                        'provider' => $providerName,
                        'error_type' => $errorType->value,
                    ]);

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    if ($this->settings->getMode() === 'manual') {
                        $this->throwChatUnavailable($failoverChain, $lastErrorType,
                            'AI unavailable (manual mode, no failover)');
                    }

                    break;
                }
            }

            $attemptIndex++;
        }

        $this->throwChatUnavailable($failoverChain, $lastErrorType,
            'All LLM providers are unavailable for text chat');
    }

    /**
     * @return iterable<LLMStreamEvent>
     * @throws LLMChatUnavailableException
     */
    public function streamChat(LLMChatRequest $request, LLMCancellationToken $cancellationToken, ?string $correlationId = null, ?string $taskProfile = null, ?LLMProfileFallbackSelection $profileFallback = null): iterable
    {
        $this->lastCorrelationId = $correlationId ?? (string) Str::uuid();
        $this->capabilityRefreshAttempts = [];
        $executionPlan = $this->buildExecutionPlan($taskProfile);
        $profile = $this->activeProfile($taskProfile);
        $this->assertProfileFallbackSelection($profile, $profileFallback);
        if ($profileFallback !== null) {
            $executionPlan = [$profileFallback->provider];
        }
        // Multimodal fallback is enabled only by an explicit task-profile policy.
        if (($request->hasFiles() || $request->hasImages()) && ! $this->profileAllowsFallback($profile)) {
            $executionPlan = array_slice($executionPlan, 0, 1);
        }
        $failoverChain = [];
        $lastErrorType = null;
        $lastProviderException = null;

        foreach ($executionPlan as $providerIndex => $providerName) {
            $provider = $this->getProvider($providerName, $profileFallback?->model ?? $this->profileModel($taskProfile, $providerName));
            $supportsStreaming = $provider !== null && $this->supports($provider, LLMCapability::STREAMING, $profile);
            if ($profile !== null && $provider !== null && ! $supportsStreaming) {
                // The response arrives as a whole; this is a synchronous completion,
                // never a claim that the selected model streamed tokens.
                if ($request->hasImages() && ! $this->supportsMediaRequest($provider, LLMCapability::IMAGE_INPUT, $profile, $taskProfile, $request)) {
                    if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                        $failoverChain[] = "{$providerName}:capability_mismatch";
                        continue;
                    }
                    throw new LLMUnsupportedCapabilityException($providerName, $provider->model(), LLMCapability::IMAGE_INPUT);
                }
                if ($request->hasFiles()) {
                    $requiredCapability = $request->hasPdfOcrFiles() ? LLMCapability::PDF_OCR : LLMCapability::FILE_INPUT;
                    if (! $this->supportsMediaRequest($provider, $requiredCapability, $profile, $taskProfile, $request)) {
                        if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                            $failoverChain[] = "{$providerName}:capability_mismatch";
                            continue;
                        }
                        throw new LLMUnsupportedCapabilityException($providerName, $provider->model(), $requiredCapability);
                    }
                }
                if ($cancellationToken->isCancellationRequested()) {
                    return;
                }
                try {
                    $response = $provider->chat($this->requestForProvider($request, $profile, $provider));
                    $this->circuitBreaker->recordSuccess($providerName);
                } catch (LLMProviderException $exception) {
                    $lastProviderException = $exception;
                    $lastErrorType = $this->errorClassifier->classify($exception, $exception->getHttpStatus());
                    $failoverChain[] = "{$providerName}:{$lastErrorType->value}";
                    $this->circuitBreaker->recordFailure($providerName, $lastErrorType->value);
                    if (count($executionPlan) === 1 || ! $lastErrorType->isFailoverAllowed()) {
                        throw $exception;
                    }
                    continue;
                }
                if ($cancellationToken->isCancellationRequested()) {
                    return;
                }
                $this->logMediaCompletion($request, $taskProfile, $provider, $response->metadata['upstream_provider'] ?? $response->provider, $response->model);
                yield LLMStreamEvent::delta($response->content);
                yield LLMStreamEvent::done([
                    ...$response->metadata,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'sync_fallback' => true,
                    'fallback_used' => $profileFallback !== null || $providerIndex > 0,
                    'fallback_reason' => $profileFallback !== null ? 'capability_mismatch' : ($providerIndex > 0 ? $this->fallbackReason($failoverChain) : null),
                    'actual_upstream_provider' => $response->metadata['upstream_provider'] ?? $response->provider,
                    'actual_upstream_model' => $response->model,
                ], $response->parsedFiles);
                return;
            }
            if (! $provider instanceof LLMStreamingProviderInterface || ! $supportsStreaming) {
                $failoverChain[] = "{$providerName}:streaming_not_supported";
                continue;
            }
            if ($request->hasImages() && ! $this->supportsMediaRequest($provider, LLMCapability::IMAGE_INPUT, $profile, $taskProfile, $request)) {
                if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                    $failoverChain[] = "{$providerName}:capability_mismatch";
                    continue;
                }
                throw new LLMUnsupportedCapabilityException($provider->name(), $provider->model(), LLMCapability::IMAGE_INPUT);
            }
            if ($request->hasFiles()) {
                $requiredCapability = $request->hasPdfOcrFiles() ? LLMCapability::PDF_OCR : LLMCapability::FILE_INPUT;
                if (! $this->supportsMediaRequest($provider, $requiredCapability, $profile, $taskProfile, $request)) {
                    if ($this->profileAllowsFallback($profile) && $providerIndex + 1 < count($executionPlan)) {
                        $failoverChain[] = "{$providerName}:capability_mismatch";
                        continue;
                    }
                    throw new LLMUnsupportedCapabilityException($provider->name(), $provider->model(), $requiredCapability);
                }
            }

            for ($attempt = 0; $attempt < self::MAX_ATTEMPTS_PER_PROVIDER; $attempt++) {
                $emitted = false;
                try {
                    foreach ($provider->streamChat($this->requestForProvider($request, $profile, $provider), $cancellationToken) as $event) {
                        if (in_array($event->type, ['delta', 'reasoning_summary'], true) && $event->text !== '') {
                            $emitted = true;
                        }
                        if ($event->type === 'done') {
                            $this->logMediaCompletion(
                                $request,
                                $taskProfile,
                                $provider,
                                $event->metadata['provider'] ?? $event->metadata['upstream_provider'] ?? $provider->name(),
                                $event->metadata['model'] ?? $provider->model(),
                            );
                        }
                        yield $event->type === 'done'
                            ? LLMStreamEvent::done([
                                ...$event->metadata,
                                'upstream_provider' => $event->metadata['provider'] ?? null,
                                'provider' => $provider->name(),
                                'model' => is_string($event->metadata['model'] ?? null) ? $event->metadata['model'] : $provider->model(),
                                'fallback_used' => $profileFallback !== null || $providerIndex > 0,
                                'fallback_reason' => $profileFallback !== null ? 'capability_mismatch' : ($providerIndex > 0 ? $this->fallbackReason($failoverChain) : null),
                                'actual_upstream_provider' => $event->metadata['provider'] ?? $provider->name(),
                                'actual_upstream_model' => is_string($event->metadata['model'] ?? null) ? $event->metadata['model'] : $provider->model(),
                            ], $event->parsedFiles)
                            : $event;
                    }
                    $this->circuitBreaker->recordSuccess($providerName);
                    return;
                } catch (LLMProviderException $exception) {
                    $lastProviderException = $exception;
                    $lastErrorType = $this->errorClassifier->classify($exception, $exception->getHttpStatus());
                    $failoverChain[] = "{$providerName}:{$lastErrorType->value}";
                    $this->circuitBreaker->recordFailure($providerName, $lastErrorType->value);
                    // Retrying after visible output would duplicate a partial answer.
                    if (! $emitted && $lastErrorType->isRetryable() && $attempt + 1 < self::MAX_ATTEMPTS_PER_PROVIDER) {
                        usleep((int) (self::RETRY_BASE_DELAY_MS * (2 ** $attempt)) * 1000);
                        continue;
                    }
                    if ($emitted || $this->settings->getMode() === 'manual') {
                        throw $exception;
                    }
                    break;
                }
            }
        }

        $this->throwChatUnavailable($failoverChain, $lastErrorType, 'Streaming is unavailable for the selected AI provider', $lastProviderException);
    }

    /**
     * Построить execution plan из настроек.
     *
     * @return string[]
     */
    public function buildExecutionPlan(?string $taskProfile = null): array
    {
        if ($taskProfile !== null && $this->profileResolver()->active($taskProfile) !== null) {
            return $this->profileResolver()->executionPlan($taskProfile);
        }
        $mode = $this->settings->getMode();
        $primary = $this->settings->getPrimaryProvider();

        if ($mode === 'manual') {
            return [$primary];
        }

        $fallbacks = $this->settings->getFallbackProviders();
        return array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    /**
     * Тестировать провайдера (ping).
     */
    public function testProvider(string $name): array
    {
        $provider = $this->getProvider($name);

        if ($provider === null) {
            return [
                'provider' => $name,
                'available' => false,
                'error' => 'Provider not configured',
            ];
        }

        $startTime = microtime(true);

        try {
            $available = $provider->isAvailable();
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'provider' => $name,
                'available' => $available,
                'latency_ms' => $latencyMs,
                'circuit_breaker' => $this->circuitBreaker->getStats($name),
                'execution_plan' => $this->buildExecutionPlan(),
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => $name,
                'available' => false,
                'error' => $e->getMessage(),
                'circuit_breaker' => $this->circuitBreaker->getStats($name),
            ];
        }
    }

    /**
     * Сбросить circuit breaker для провайдера.
     */
    public function resetCircuitBreaker(string $name): void
    {
        $this->circuitBreaker->reset($name);
    }

    // -------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------

    private function getProvider(string $name, ?string $modelOverride = null): ?LLMProviderInterface
    {
        $cacheKey = $name.'|'.($modelOverride ?? '');
        if (isset($this->providerInstances[$cacheKey])) {
            return $this->providerInstances[$cacheKey];
        }

        $providerSettings = $this->settings->getProviderSettings($name);
        if ($modelOverride !== null) {
            $providerSettings['model'] = $modelOverride;
        }
        $provider = $this->providerFactory !== null
            ? ($this->providerFactory)($name, $providerSettings)
            : ProviderRegistry::createProvider($name, $providerSettings);

        if ($provider !== null) {
            $this->providerInstances[$cacheKey] = $provider;
        }

        return $provider;
    }

    private function profileResolver(): LLMTaskProfileResolver
    {
        return $this->taskProfiles ??= app(LLMTaskProfileResolver::class);
    }

    private function activeProfile(?string $task): ?array
    {
        return $task === null ? null : $this->profileResolver()->active($task);
    }

    private function assertProfileFallbackSelection(?array $profile, ?LLMProfileFallbackSelection $selection): void
    {
        if ($selection === null) {
            return;
        }
        if ($profile === null || ($profile['fallback_enabled'] ?? false) !== true
            || ($profile['fallback_provider'] ?? null) !== $selection->provider
            || ($profile['fallback_model'] ?? null) !== $selection->model) {
            throw new \InvalidArgumentException('Selected profile fallback is not configured.');
        }
    }

    private function profileModel(?string $task, string $provider): ?string
    {
        if ($task === null) {
            return null;
        }
        $profile = $this->profileResolver()->active($task);
        if ($profile === null) {
            return null;
        }
        if ($provider === ($profile['provider'] ?? null)) {
            return is_string($profile['model'] ?? null) ? $profile['model'] : null;
        }
        if (($profile['fallback_enabled'] ?? false) === true && $provider === ($profile['fallback_provider'] ?? null)) {
            return is_string($profile['fallback_model'] ?? null) ? $profile['fallback_model'] : null;
        }

        return null;
    }

    private function requestForProvider(LLMChatRequest $request, ?array $profile, LLMProviderInterface $provider): LLMChatRequest
    {
        if ($profile === null) {
            return $request;
        }

        $parameters = [];
        if (isset($profile['max_output_tokens']) && (int) $profile['max_output_tokens'] > 0) {
            $parameters['max_tokens'] = (int) $profile['max_output_tokens'];
        }
        if (isset($profile['temperature']) && is_numeric($profile['temperature'])) {
            $parameters['temperature'] = (float) $profile['temperature'];
        }
        if (is_string($profile['reasoning_effort'] ?? null)
            && $profile['reasoning_effort'] !== ''
            && $this->supports($provider, LLMCapability::REASONING, $profile)) {
            $parameters['reasoning_effort'] = $profile['reasoning_effort'];
        }

        return $parameters === [] ? $request : $request->withParameters($parameters);
    }

    private function supports(LLMProviderInterface $provider, LLMCapability $capability, ?array $profile): bool
    {
        if ($profile !== null && ($provider->name() === ($profile['provider'] ?? null) || $provider->name() === ($profile['fallback_provider'] ?? null))) {
            return app(LLMEffectiveCapabilityResolver::class)->resolve($provider->name(), $provider->model())[$capability->value] ?? false;
        }

        return in_array($capability, $provider->capabilities(), true);
    }

    private function profileAllowsFallback(?array $profile): bool
    {
        return $profile !== null
            && ($profile['fallback_enabled'] ?? false) === true
            && ProviderRegistry::exists((string) ($profile['fallback_provider'] ?? ''))
            && is_string($profile['fallback_model'] ?? null)
            && $profile['fallback_model'] !== '';
    }

    /** @param list<string> $failoverChain */
    private function fallbackReason(array $failoverChain): ?string
    {
        if ($failoverChain === []) {
            return null;
        }
        $last = (string) end($failoverChain);
        $reason = str_contains($last, ':') ? (string) substr($last, strrpos($last, ':') + 1) : $last;

        return match ($reason) {
            'timeout' => 'primary_timeout',
            'rate_limit' => 'primary_rate_limit',
            'network', 'server_error', 'unknown' => 'primary_unavailable',
            'capability_mismatch' => 'capability_mismatch',
            default => 'primary_unavailable',
        };
    }

    private function withFallbackMetadata(LLMChatResponse $response, bool $used, ?string $reason): LLMChatResponse
    {
        return new LLMChatResponse(
            provider: $response->provider,
            model: $response->model,
            content: $response->content,
            latencyMs: $response->latencyMs,
            promptTokens: $response->promptTokens,
            completionTokens: $response->completionTokens,
            costUsd: $response->costUsd,
            totalTokens: $response->totalTokens,
            cachedTokens: $response->cachedTokens,
            reasoningTokens: $response->reasoningTokens,
            metadata: [
                ...$response->metadata,
                'fallback_used' => $used,
                'fallback_reason' => $used ? $reason : null,
                'upstream_model' => $response->model,
                'actual_upstream_provider' => $response->metadata['upstream_provider'] ?? $response->provider,
                'actual_upstream_model' => $response->model,
            ],
            parsedFiles: $response->parsedFiles,
        );
    }

    private function supportsMediaRequest(
        LLMProviderInterface $provider,
        LLMCapability $capability,
        ?array $profile,
        ?string $taskProfile,
        LLMChatRequest $request,
    ): bool {
        if ($this->supports($provider, $capability, $profile)) {
            return true;
        }
        if ($provider->name() !== 'routerai') {
            return false;
        }

        $key = $provider->name().'|'.$provider->model();
        $catalog = app(RouterAiModelCatalogService::class);
        $snapshot = null;
        if (! isset($this->capabilityRefreshAttempts[$key])) {
            $this->capabilityRefreshAttempts[$key] = true;
            $snapshot = $catalog->snapshot(true);
        }

        $resolver = app(LLMEffectiveCapabilityResolver::class);
        $capabilities = $resolver->resolve($provider->name(), $provider->model());
        $supportedAfterRefresh = (bool) ($capabilities[$capability->value] ?? false);
        Log::warning('LLMRouter: RouterAI media capability self-healing decision.', [
            'correlation_id' => $this->lastCorrelationId,
            'task_profile' => $taskProfile,
            'effective_provider' => $provider->name(),
            'effective_model' => $provider->model(),
            'catalog_status' => is_array($snapshot) ? ($snapshot['status'] ?? $catalog->cachedStatus()) : $catalog->cachedStatus(),
            'capability_source' => $resolver->source($provider->name(), $provider->model(), $capability),
            'required_capability' => $capability->value,
            'image_input' => (bool) ($capabilities[LLMCapability::IMAGE_INPUT->value] ?? false),
            'file_input' => (bool) ($capabilities[LLMCapability::FILE_INPUT->value] ?? false),
            'pdf_ocr' => (bool) ($capabilities[LLMCapability::PDF_OCR->value] ?? false),
            'catalog_refreshed' => is_array($snapshot),
            'advisory_forwarded' => ! $supportedAfterRefresh,
            'request_has_image' => $request->hasImages(),
            'request_has_file' => $request->hasFiles(),
            'actual_upstream_provider' => null,
            'actual_upstream_model' => null,
        ]);

        // RouterAI catalog negatives can be stale or incomplete. The pinned model remains
        // unchanged and the upstream API is the final authority for this media request.
        return true;
    }

    private function logMediaCompletion(
        LLMChatRequest $request,
        ?string $taskProfile,
        LLMProviderInterface $provider,
        mixed $actualProvider,
        mixed $actualModel,
    ): void {
        if ($provider->name() !== 'routerai' || (! $request->hasImages() && ! $request->hasFiles())) {
            return;
        }

        $resolver = app(LLMEffectiveCapabilityResolver::class);
        $capabilities = $resolver->resolve($provider->name(), $provider->model());
        $requiredCapability = $request->hasImages()
            ? LLMCapability::IMAGE_INPUT
            : ($request->hasPdfOcrFiles() ? LLMCapability::PDF_OCR : LLMCapability::FILE_INPUT);

        Log::info('LLMRouter: RouterAI media request completed.', [
            'correlation_id' => $this->lastCorrelationId,
            'task_profile' => $taskProfile,
            'effective_provider' => $provider->name(),
            'effective_model' => $provider->model(),
            'catalog_status' => app(RouterAiModelCatalogService::class)->cachedStatus(),
            'capability_source' => $resolver->source($provider->name(), $provider->model(), $requiredCapability),
            'image_input' => (bool) ($capabilities[LLMCapability::IMAGE_INPUT->value] ?? false),
            'file_input' => (bool) ($capabilities[LLMCapability::FILE_INPUT->value] ?? false),
            'pdf_ocr' => (bool) ($capabilities[LLMCapability::PDF_OCR->value] ?? false),
            'catalog_refreshed' => $this->capabilityRefreshAttempts !== [],
            'actual_upstream_provider' => is_string($actualProvider) ? $actualProvider : null,
            'actual_upstream_model' => is_string($actualModel) ? $actualModel : null,
        ]);
    }

    /**
     * @throws LLMUnavailableException
     * @return never
     */
    private function throwUnavailable(
        DecompositionPrompt $prompt,
        LLMLogger $logger,
        array $failoverChain,
        array $executionPlan,
        string $message,
    ): never {
        $logger->logAllFailed(
            prompt: $prompt,
            failoverChain: $failoverChain,
            executionPlan: $executionPlan,
            errorMessage: $message,
            userId: $this->currentUserId,
        );

        throw new LLMUnavailableException(
            message: $message,
            failoverChain: $failoverChain,
        );
    }

    /**
     * @throws LLMChatUnavailableException
     * @return never
     */
    private function throwChatUnavailable(
        array $failoverChain,
        ?LLMErrorType $lastErrorType,
        string $message,
        ?LLMProviderException $previous = null,
    ): never {
        throw new LLMChatUnavailableException(
            message: $message,
            failoverChain: $failoverChain,
            lastErrorType: $lastErrorType,
            previous: $previous,
        );
    }
}
