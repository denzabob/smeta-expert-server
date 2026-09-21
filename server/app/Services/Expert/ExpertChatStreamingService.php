<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Http\Resources\Expert\MessageResource;
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
use App\Services\LLM\LLMTaskProfileResolver;
use Illuminate\Support\Facades\Log;

final class ExpertChatStreamingService
{
    public function __construct(
        private readonly ExpertChatService $chat,
        private readonly ExpertChatRunRegistry $runs,
        private readonly LLMRouter $router,
        private readonly ExpertChatMaterialContextBuilder $materialContextBuilder,
        private readonly ExpertChatMaterialContextDiagnostics $materialDiagnostics,
        private readonly ExpertPdfOcrCache $ocrCache,
        private readonly LLMTaskProfileResolver $profiles,
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
            $materialPublicIds = $this->materialPublicIds($materialContextOrPublicIds);
            $existingUser = $conversation->messages()->where('role', 'user')->where('metadata->client_message_id', $clientMessageId)->first();
            $persistedIds = $existingUser === null ? $materialPublicIds : $this->chat->persistedMaterialPublicIds($existingUser);
            $historicalIds = $this->chat->historicalMaterialPublicIds($conversation, $content, $existingUser, hasCurrentMaterials: $persistedIds !== []);
            $plan = $this->chat->contextPlan($conversation, $content, $persistedIds, $historicalIds, $existingUser);
            if ($plan->diagnostics['requires_material_disambiguation'] ?? false) {
                throw ExpertMaterialContextException::ambiguousActiveMaterials();
            }
            if ($plan->requiresMultiDocumentPipeline) {
                throw ExpertMaterialContextException::multiDocumentPipelineRequired();
            }
            $userMessage = $this->chat->prepareStreamingUserMessage($conversation, $content, $clientMessageId, $fingerprint, $materialPublicIds);
            $this->chat->recordContext($conversation, $userMessage, $plan);
            $existingAssistant = $this->chat->assistantReplyFor($conversation, $userMessage);
            $registryRun = $this->runs->create($conversation, $existingAssistant?->public_id);

            return new ExpertChatStreamingRun(
                $conversation,
                $userMessage,
                $materialContextOrPublicIds instanceof ExpertChatMaterialContext ? $materialContextOrPublicIds : null,
                $persistedIds,
                $registryRun,
                $lock,
                $existingAssistant,
                false,
                $plan,
            );
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }
    }

    public function continueRun(
        ExpertConversation $conversation,
        ExpertMessage $assistantMessage,
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
        $persistedPublicIds = $this->chat->persistedMaterialPublicIds($userMessage);
        $historicalIds = $this->chat->historicalMaterialPublicIds($conversation, $userMessage->content, $userMessage, hasCurrentMaterials: $persistedPublicIds !== []);
        $plan = $this->chat->contextPlan($conversation, $userMessage->content, $persistedPublicIds, $historicalIds, $userMessage);

        $lock = $this->runs->acquireConversation($conversation);
        try {
            $registryRun = $this->runs->create($conversation, $assistantMessage->public_id);

            return new ExpertChatStreamingRun(
                $conversation,
                $userMessage,
                null,
                $persistedPublicIds,
                $registryRun,
                $lock,
                $assistantMessage,
                true,
                $plan,
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
        $retryable = false;
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
            $historicalIds = $run->contextPack?->historicalMaterials ?? [];
            $materialBundle = $run->materialContext === null
                ? $this->materialContextBuilder->buildPartitioned(
                    $run->conversation->project,
                    $run->materialPublicIds,
                    $historicalIds,
                    $activity,
                    $run->contextPack === null ? [] : array_values(array_diff($run->contextPack->resolvedMaterials, $run->contextPack->currentMaterials, $run->contextPack->historicalMaterials)),
                    $run->contextPack,
                )
                : ExpertChatMaterialContextBundle::currentOnly($run->materialContext);
            $this->materialDiagnostics->log($runId, $materialBundle, $run->materialPublicIds, $historicalIds);
            $materialContext = $materialBundle->combined();

            $request = $run->isContinuation
                ? $this->chat->buildContinuationRequest($run->conversation, $run->userMessage, $run->existingAssistant, $materialBundle)
                : $this->chat->buildStreamingRequest($run->conversation, $run->userMessage, $materialBundle);

            foreach ($materialContext->ocrCandidates as $candidate) {
                $ocrActivityIds[strtolower($candidate->sha256)] = $activity->start(
                    $candidate->processingIntent === \App\Services\LLM\Enums\LLMFileProcessingIntent::PDF_TEXT_PARSE
                        ? 'pdf.text.started'
                        : 'pdf.ocr.started',
                    'material',
                    $candidate->name,
                );
            }
            $modelActivityId = $activity->start('model.request.started', 'model');

            foreach ($this->router->setUserId($run->conversation->project->user_id)->streamChat($request, $token, $runId, LLMTaskProfileResolver::EXPERT_CHAT) as $event) {
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
                    $this->persistOcrResults($materialBundle, $event->parsedFiles, $ocrActivityIds, $activity, $runId);
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
            $retryable = $this->isRetryableError($errorCode, $hasVisibleOutput);
            $this->logFailure($runId, $errorCode, $retryable, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt);
        } catch (\Throwable $exception) {
            $status = $token->isCancellationRequested() ? 'stopped' : ($hasVisibleOutput || $content !== '' ? 'interrupted' : 'failed');
            $finishReason = $status === 'stopped' ? 'cancelled' : 'error';
            $errorCode = $this->errorCode($exception) ?? $errorCode;
            $retryable = $this->isRetryableError($errorCode, $hasVisibleOutput);
            $this->logFailure($runId, $errorCode, $retryable, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt);
        } finally {
            if ($status === 'completed' && $activity->openActivityCodes() !== []) {
                Log::warning('Expert chat completed with open activities.', [
                    'run_id' => $runId,
                    'activity_codes' => $activity->openActivityCodes(),
                ]);
            }
            $activity->terminalize(match ($status) {
                'completed' => 'completed',
                'stopped' => 'skipped',
                default => 'failed',
            });
            $assistant = $this->chat->persistStreamingAssistant(
                $run->conversation,
                $run->userMessage,
                $content,
                $status === 'completed' ? 'completed' : ($status === 'stopped' ? 'stopped' : 'interrupted'),
                $runId,
                $finishReason,
                [...$metadata, 'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000)],
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
            'error_code' => $errorCode,
            'run_id' => $runId,
            'retryable' => $retryable,
            'last_activity_code' => $activity->lastActivityCode(),
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
        ExpertChatMaterialContextBundle $materialBundle,
        array $parsedFiles,
        array $ocrActivityIds,
        ExpertRunActivitySink $activity,
        string $runId,
    ): void {
        foreach ($materialBundle->combined()->ocrCandidates as $candidate) {
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
                    $activity->complete(
                        $activityId,
                        $candidate->processingIntent === \App\Services\LLM\Enums\LLMFileProcessingIntent::PDF_TEXT_PARSE
                            ? 'pdf.text.completed'
                            : 'pdf.ocr.completed',
                    );
                }
                $this->materialDiagnostics->logProviderPdf(
                    $runId,
                    $materialBundle,
                    $candidate,
                    mb_strlen($parsed->text, 'UTF-8'),
                );
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
                LLMCapability::FILE_INPUT => 'material_not_supported',
                default => 'streaming_not_supported',
            };
        }
        if ($exception instanceof LLMProviderException) {
            return match (true) {
                $exception->getErrorType() === 'stream_malformed' => 'stream_malformed',
                $exception->getErrorType() === 'stream_eof_without_terminal' => 'stream_eof_without_terminal',
                $exception->getErrorType() === 'unexpected_content_type' => 'provider_unexpected_content_type',
                in_array($exception->getErrorType(), ['auth', 'config'], true) => 'provider_auth_failed',
                $exception->getHttpStatus() === 404 => 'provider_model_not_found',
                in_array($exception->getErrorType(), ['request_error'], true) => 'provider_validation_failed',
                $exception->getErrorType() === 'http_429' => 'provider_rate_limited',
                $exception->getErrorType() === 'http_5xx' => 'provider_server_error',
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
                LLMErrorType::SERVER_ERROR => 'provider_server_error',
                default => 'expert_stream_interrupted',
            };
        }

        return null;
    }

    private function isRetryableError(string $errorCode, bool $hasVisibleOutput): bool
    {
        return ! $hasVisibleOutput && ! in_array($errorCode, [
            'provider_auth_failed',
            'provider_model_not_found',
            'provider_validation_failed',
            'streaming_not_supported',
            'vision_not_supported',
            'pdf_ocr_failed',
            'pdf_processing_too_large',
            'material_not_supported',
        ], true);
    }

    private function logFailure(string $runId, string $code, bool $retryable, \Throwable $exception, ?string $activityCode, float $startedAt, ?float $firstDeltaAt): void
    {
        $rootException = $exception instanceof LLMChatUnavailableException && $exception->getPrevious() instanceof LLMProviderException
            ? $exception->getPrevious() : $exception;
        $profile = $this->profiles->effective(LLMTaskProfileResolver::EXPERT_CHAT);
        $effectiveProvider = $profile['effective']['provider'] ?? null;
        $effectiveModel = $profile['effective']['model'] ?? null;
        $actualProvider = $rootException instanceof LLMProviderException ? $rootException->getProvider() : null;
        $safeProvider = static fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9_-]{1,40}$/i', $value) ? $value : null;
        $safeModel = static fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9._\/-]{1,100}$/i', $value) ? $value : null;
        $httpStatus = $rootException instanceof LLMProviderException ? $rootException->getHttpStatus() : null;

        Log::warning('Expert chat stream failed.', [
            'run_id' => $runId,
            'task_profile' => LLMTaskProfileResolver::EXPERT_CHAT,
            'effective_provider' => $safeProvider($effectiveProvider),
            'effective_model' => $safeModel($effectiveModel),
            'profile_source' => $profile['source'] ?? null,
            'actual_upstream_provider' => $safeProvider($actualProvider),
            'actual_upstream_model' => null,
            'root_error_code' => $code,
            'retryable' => $retryable,
            'before_first_delta' => $firstDeltaAt === null,
            'last_activity_code' => $activityCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'ttft_ms' => $firstDeltaAt === null ? null : (int) round(($firstDeltaAt - $startedAt) * 1000),
            'http_status_class' => $httpStatus === null ? null : intdiv($httpStatus, 100).'xx',
        ]);
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
        return (new MessageResource($message->loadMissing('attachments.material')))->resolve();
    }
}
