<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMParsedFile;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Enums\LLMErrorType;
use App\Services\LLM\Exceptions\LLMChatUnavailableException;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Exceptions\LLMUnsupportedCapabilityException;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use Illuminate\Support\Facades\Log;

final class ExpertChatStreamingService
{
    public function __construct(
        private readonly ExpertChatService $chat,
        private readonly ExpertChatRunRegistry $runs,
        private readonly LLMRouter $router,
        private readonly ExpertChatMaterialContextBuilder $materialContextBuilder,
        private readonly ExpertPdfOcrCache $ocrCache,
        private readonly LLMSettingsRepository $settings,
    ) {}

    /**
     * @param  ExpertChatMaterialContext|list<string>  $materialContextOrPublicIds
     *
     * A prepared context is retained as a narrow test seam for the 4D.1
     * lifecycle tests. HTTP streaming runs pass public IDs so material work can
     * be observed only after the SSE run has been opened.
     */
    public function start(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $fingerprint,
        ExpertChatMaterialContext|array $materialContextOrPublicIds,
    ): ExpertChatStreamingRun {
        $lock = $this->runs->acquireConversation($conversation);
        try {
            $userMessage = $this->chat->prepareStreamingUserMessage($conversation, $content, $clientMessageId, $fingerprint);
            $existingAssistant = $this->chat->assistantReplyFor($conversation, $userMessage);
            $registryRun = $this->runs->create($conversation, $existingAssistant?->public_id);

            return new ExpertChatStreamingRun(
                $conversation,
                $userMessage,
                $materialContextOrPublicIds instanceof ExpertChatMaterialContext ? $materialContextOrPublicIds : null,
                $this->materialPublicIds($materialContextOrPublicIds),
                $registryRun,
                $lock,
                $existingAssistant,
            );
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }
    }

    /** @param ExpertChatMaterialContext|list<string> $materialContextOrPublicIds */
    public function continueRun(
        ExpertConversation $conversation,
        ExpertMessage $assistantMessage,
        string $content,
        string $fingerprint,
        ExpertChatMaterialContext|array $materialContextOrPublicIds,
    ): ExpertChatStreamingRun {
        if ($assistantMessage->role !== 'assistant' || ! in_array((is_array($assistantMessage->metadata) ? $assistantMessage->metadata['generation_status'] ?? null : null), ['stopped', 'interrupted'], true)) {
            throw new ExpertChatStreamException('Этот ответ нельзя продолжить.');
        }
        $replyTo = is_array($assistantMessage->metadata) ? $assistantMessage->metadata['in_reply_to'] ?? null : null;
        $userMessage = is_string($replyTo)
            ? $conversation->messages()->where('role', 'user')->where('public_id', $replyTo)->first()
            : null;
        if ($userMessage === null) {
            throw new ExpertChatStreamException('Исходное сообщение для продолжения не найдено.');
        }
        $this->chat->assertStreamingSnapshot($userMessage, $content, $fingerprint);

        $lock = $this->runs->acquireConversation($conversation);
        try {
            $registryRun = $this->runs->create($conversation, $assistantMessage->public_id);

            return new ExpertChatStreamingRun(
                $conversation,
                $userMessage,
                $materialContextOrPublicIds instanceof ExpertChatMaterialContext ? $materialContextOrPublicIds : null,
                $this->materialPublicIds($materialContextOrPublicIds),
                $registryRun,
                $lock,
                $assistantMessage,
                true,
            );
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }
    }

    /** @param \Closure(string, array<string, mixed>): void $emit */
    public function emit(ExpertChatStreamingRun $run, \Closure $emit, ?\Closure $clientDisconnected = null): void
    {
        $runId = $run->runId();
        $content = $run->existingAssistant?->content ?? '';
        $metadata = [];
        $status = 'completed';
        $finishReason = 'stop';
        $errorCode = 'expert_stream_interrupted';
        $hasVisibleOutput = false;
        $deltaSequence = 0;
        $reasoningSequence = 0;
        $startedAt = microtime(true);
        $firstDeltaAt = null;
        $lastHeartbeatAt = $startedAt;
        $assistant = null;
        $modelActivityId = null;
        /** @var array<string, string> $ocrActivityIds */
        $ocrActivityIds = [];

        $isCancellationRequested = function () use ($runId, $clientDisconnected): bool {
            if ($clientDisconnected !== null && $clientDisconnected()) {
                $this->runs->requestCancellation($runId);
            }

            return $this->runs->isCancellationRequested($runId);
        };
        $token = new LLMCancellationToken($isCancellationRequested);
        $activity = new SseExpertRunActivitySink($runId, $emit, $isCancellationRequested);

        $this->runs->mark($runId, 'streaming');
        $emit('run', [
            'version' => 1,
            'run_id' => $runId,
            'user_message' => $this->messagePayload($run->userMessage),
        ]);
        $activity->record('request.accepted', 'request');

        if ($run->existingAssistant !== null && ! $run->isContinuation) {
            $storedStatus = is_array($run->existingAssistant->metadata) ? ($run->existingAssistant->metadata['generation_status'] ?? 'completed') : 'completed';
            $event = in_array($storedStatus, ['stopped', 'interrupted'], true) ? 'cancelled' : 'done';
            $emit($event, [
                'version' => 1,
                'assistant_message' => $this->messagePayload($run->existingAssistant),
                'finish_reason' => $storedStatus === 'completed' ? 'stop' : 'cancelled',
            ]);
            $this->runs->mark($runId, $storedStatus === 'completed' ? 'completed' : 'cancelled');
            $run->lock->release();

            return;
        }

        try {
            $materialContext = $run->materialContext
                ?? $this->materialContextBuilder->build($run->conversation->project, $run->materialPublicIds, $activity);

            $request = $run->isContinuation
                ? $this->chat->buildContinuationRequest($run->conversation, $run->userMessage, $run->existingAssistant, $materialContext)
                : $this->chat->buildStreamingRequest($run->conversation, $run->userMessage, $materialContext);

            foreach ($materialContext->ocrCandidates as $candidate) {
                $ocrActivityIds[strtolower($candidate->sha256)] = $activity->start('pdf.ocr.started', 'material', $candidate->name);
            }
            $modelActivityId = $activity->start('model.request.started', 'model');

            foreach ($this->router->setUserId($run->conversation->project->user_id)->streamChat($request, $token, $runId) as $event) {
                if ($token->isCancellationRequested()) {
                    $status = 'stopped';
                    $finishReason = 'cancelled';
                    break;
                }
                if ($event->type === 'delta' && $event->text !== '') {
                    $firstDeltaAt ??= microtime(true);
                    $hasVisibleOutput = true;
                    $content .= $event->text;
                    if ($modelActivityId !== null) {
                        $activity->complete($modelActivityId, 'model.first_token');
                        $modelActivityId = null;
                    }
                    $emit('delta', ['version' => 1, 'seq' => ++$deltaSequence, 'text' => $event->text]);
                } elseif ($event->type === 'reasoning_summary' && $event->text !== '') {
                    // The DTO is produced only from the explicit provider field;
                    // generic/raw reasoning is discarded by the parser.
                    $hasVisibleOutput = true;
                    $emit('reasoning_summary', [
                        'version' => 1,
                        'run_id' => $runId,
                        'seq' => ++$reasoningSequence,
                        'text' => $event->text,
                        'final' => $event->isFinal,
                    ]);
                } elseif ($event->type === 'heartbeat' || microtime(true) - $lastHeartbeatAt >= (int) config('expert.streaming.heartbeat_seconds', 15)) {
                    $emit('heartbeat', ['version' => 1]);
                    $lastHeartbeatAt = microtime(true);
                } elseif ($event->type === 'done') {
                    $metadata = $event->metadata;
                    $this->persistOcrResults($materialContext, $event->parsedFiles, $ocrActivityIds, $activity);
                }
                if (microtime(true) - $startedAt > (int) config('expert.streaming.absolute_timeout_seconds', 600)) {
                    throw LLMProviderException::timeout('stream', (int) config('expert.streaming.absolute_timeout_seconds', 600));
                }
            }
            if ($token->isCancellationRequested()) {
                $status = 'stopped';
                $finishReason = 'cancelled';
            } elseif ($status === 'completed') {
                if ($modelActivityId !== null) {
                    $activity->complete($modelActivityId, 'model.completed');
                    $modelActivityId = null;
                } else {
                    $activity->record('model.completed', 'model');
                }
            }
        } catch (ExpertChatStreamingCancelledException) {
            $status = 'stopped';
            $finishReason = 'cancelled';
        } catch (LLMProviderException|LLMChatUnavailableException $exception) {
            $status = $token->isCancellationRequested() ? 'stopped' : ($hasVisibleOutput || $content !== '' ? 'interrupted' : 'failed');
            $finishReason = $status === 'stopped' ? 'cancelled' : 'error';
            $errorCode = $this->errorCode($exception) ?? $errorCode;
            $this->logFailure($runId, $errorCode, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt);
        } catch (\Throwable $exception) {
            $status = $token->isCancellationRequested() ? 'stopped' : ($hasVisibleOutput || $content !== '' ? 'interrupted' : 'failed');
            $finishReason = $status === 'stopped' ? 'cancelled' : 'error';
            $errorCode = $this->errorCode($exception) ?? $errorCode;
            $this->logFailure($runId, $errorCode, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt);
        } finally {
            $activity->terminalize($status === 'stopped' ? 'skipped' : 'failed');
            $assistant = $this->chat->persistStreamingAssistant(
                $run->conversation,
                $run->userMessage,
                $content,
                $status === 'completed' ? 'completed' : ($status === 'stopped' ? 'stopped' : 'interrupted'),
                $runId,
                $finishReason,
                $metadata,
                $run->existingAssistant,
            );
            if ($assistant !== null) {
                $activity->record('response.persisted', 'response');
            }
            if ($status === 'stopped') {
                $activity->record('generation.cancelled', 'generation');
            } elseif ($status === 'interrupted') {
                $activity->record('generation.interrupted', 'generation');
            }
            $this->runs->mark($runId, match ($status) {
                'completed' => 'completed',
                'stopped' => 'cancelled',
                'interrupted' => 'interrupted',
                default => 'failed',
            });
            $run->lock->release();
        }

        if ($status === 'completed') {
            $emit('done', ['version' => 1, 'assistant_message' => $assistant === null ? null : $this->messagePayload($assistant), 'finish_reason' => $finishReason]);

            return;
        }
        if ($status === 'stopped') {
            $emit('cancelled', ['version' => 1, 'assistant_message' => $assistant === null ? null : $this->messagePayload($assistant), 'finish_reason' => 'cancelled']);

            return;
        }
        $emit('error', [
            'version' => 1,
            'code' => $errorCode,
            'retryable' => ! $hasVisibleOutput && ! in_array($errorCode, ['provider_auth_failed', 'provider_model_not_found', 'provider_validation_failed', 'streaming_not_supported', 'vision_not_supported'], true),
            'assistant_message' => $assistant === null ? null : $this->messagePayload($assistant),
        ]);
    }

    /** @param ExpertChatMaterialContext|list<string> $materialContextOrPublicIds @return list<string> */
    private function materialPublicIds(ExpertChatMaterialContext|array $materialContextOrPublicIds): array
    {
        if ($materialContextOrPublicIds instanceof ExpertChatMaterialContext) {
            return [];
        }

        return array_values(array_filter($materialContextOrPublicIds, static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }

    /**
     * @param  list<LLMParsedFile>  $parsedFiles
     * @param  array<string, string>  $ocrActivityIds
     */
    private function persistOcrResults(
        ExpertChatMaterialContext $materialContext,
        array $parsedFiles,
        array $ocrActivityIds,
        ExpertRunActivitySink $activity,
    ): void {
        foreach ($materialContext->ocrCandidates as $candidate) {
            $candidateHash = strtolower($candidate->sha256);
            $activityId = $ocrActivityIds[$candidateHash] ?? null;
            $parsed = null;
            foreach ($parsedFiles as $file) {
                if (strtolower($file->sha256) === $candidateHash) {
                    $parsed = $file;
                    break;
                }
            }
            if ($parsed === null) {
                if ($activityId !== null) {
                    $activity->fail($activityId, 'pdf_ocr_failed');
                }
                throw ExpertPdfOcrException::failed();
            }

            try {
                $this->ocrCache->put($candidate, $parsed);
                if ($activityId !== null) {
                    $activity->complete($activityId, 'pdf.ocr.completed');
                }
            } catch (\Throwable $exception) {
                if ($activityId !== null) {
                    $activity->fail($activityId, $this->errorCode($exception));
                }
                throw $exception;
            }
        }
    }

    private function errorCode(\Throwable $exception): ?string
    {
        if ($exception instanceof ExpertMaterialContextException || $exception instanceof ExpertPdfOcrException || $exception instanceof ExpertVisionException) {
            return $exception->errorCode;
        }
        if ($exception instanceof LLMUnsupportedCapabilityException) {
            return match ($exception->capability) {
                LLMCapability::IMAGE_INPUT => 'vision_not_supported',
                LLMCapability::PDF_OCR => 'pdf_ocr_failed',
                default => 'streaming_not_supported',
            };
        }
        if ($exception instanceof LLMProviderException) {
            return match (true) {
                $exception->getErrorType() === 'stream_malformed' => 'stream_malformed',
                $exception->getErrorType() === 'stream_eof_without_terminal' => 'stream_eof_without_terminal',
                in_array($exception->getErrorType(), ['auth', 'config'], true) => 'provider_auth_failed',
                $exception->getHttpStatus() === 404 => 'provider_model_not_found',
                in_array($exception->getErrorType(), ['request_error'], true) => 'provider_validation_failed',
                $exception->getErrorType() === 'http_429' => 'provider_rate_limited',
                $exception->getErrorType() === 'timeout' => 'provider_timeout',
                $exception->getErrorType() === 'network' => 'provider_connection_failed',
                default => 'expert_stream_interrupted',
            };
        }
        if ($exception instanceof LLMChatUnavailableException) {
            $failoverChain = $exception->getFailoverChain();
            if ($this->isOnlyStreamingUnsupported($failoverChain)) {
                return 'streaming_not_supported';
            }
            if ($exception->getPrevious() instanceof LLMProviderException) {
                return $this->errorCode($exception->getPrevious());
            }

            return match ($exception->lastErrorType()) {
                LLMErrorType::AUTH, LLMErrorType::CONFIG => 'provider_auth_failed',
                LLMErrorType::REQUEST_ERROR => 'provider_validation_failed',
                LLMErrorType::RATE_LIMIT => 'provider_rate_limited',
                LLMErrorType::TIMEOUT => 'provider_timeout',
                LLMErrorType::NETWORK => 'provider_connection_failed',
                default => 'expert_stream_interrupted',
            };
        }

        return null;
    }

    private function logFailure(string $runId, string $code, \Throwable $exception, ?string $activityCode, float $startedAt, ?float $firstDeltaAt): void
    {
        $rootException = $exception instanceof LLMChatUnavailableException && $exception->getPrevious() instanceof LLMProviderException
            ? $exception->getPrevious() : $exception;
        $provider = $rootException instanceof LLMProviderException ? $rootException->getProvider() : $this->settings->getPrimaryProvider();
        $provider = preg_match('/^[a-z0-9_-]{1,40}$/i', $provider) ? $provider : 'unknown';
        $model = $this->settings->getProviderSettings($provider)['model'] ?? null;
        $model = is_string($model) && preg_match('/^[a-z0-9._\/-]{1,100}$/i', $model) ? $model : null;
        $httpStatus = $rootException instanceof LLMProviderException ? $rootException->getHttpStatus() : null;

        Log::warning('Expert chat stream failed.', array_filter([
            'run_id' => $runId,
            'provider' => $provider,
            'model' => $model,
            'root_error_code' => $code,
            'before_first_delta' => $firstDeltaAt === null,
            'last_activity_code' => $activityCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ttft_ms' => $firstDeltaAt === null ? null : (int) round(($firstDeltaAt - $startedAt) * 1000),
            'http_status_class' => $httpStatus === null ? null : intdiv($httpStatus, 100).'xx',
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @param list<mixed> $failoverChain */
    private function isOnlyStreamingUnsupported(array $failoverChain): bool
    {
        if ($failoverChain === []) {
            return false;
        }

        foreach ($failoverChain as $entry) {
            if (! is_string($entry) || ! str_ends_with($entry, ':streaming_not_supported')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function messagePayload(ExpertMessage $message): array
    {
        return [
            'public_id' => $message->public_id,
            'role' => $message->role,
            'content' => $message->content,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at?->toIso8601String(),
            'updated_at' => $message->updated_at?->toIso8601String(),
        ];
    }
}
