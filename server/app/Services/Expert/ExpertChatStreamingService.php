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
use Illuminate\Support\Str;

final class ExpertChatStreamingService
{
    public function __construct(
        private readonly ExpertChatService $chat,
        private readonly ExpertChatRunRegistry $runs,
        private readonly ExpertAiRunEventRecorder $runEvents,
        private readonly LLMRouter $router,
        private readonly ExpertChatMaterialContextBuilder $materialContextBuilder,
        private readonly ExpertChatMaterialContextDiagnostics $materialDiagnostics,
        private readonly ExpertPdfOcrCache $ocrCache,
        private readonly ExpertMaterialIdentityService $identities,
        private readonly LLMTaskProfileResolver $profiles,
    ) {}

    /**
     * @param  ExpertChatMaterialContext|list<string>|null  $materialContextOrPublicIds
     *
     * A prepared context is retained as a narrow test seam for the 4D.1
     * lifecycle tests. HTTP streaming runs pass public IDs so material work can
     * be observed only after the SSE run has been opened.
     */
    public function start(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        ?string $fingerprint,
        ExpertChatMaterialContext|array|null $materialContextOrPublicIds,
        string $requestedMode = ExpertModeResolution::AUTO,
    ): ExpertChatStreamingRun {
        $startedAt = microtime(true);
        $runId = (string) Str::uuid();
        $registryRun = null;
        $lock = null;

        try {
            $registryRun = $this->runs->create(
                $conversation,
                startedAt: $startedAt,
                clientMessageId: $clientMessageId,
                requestedMode: $requestedMode,
                selectedMaterialCount: is_array($materialContextOrPublicIds) ? count($materialContextOrPublicIds) : 0,
                runId: $runId,
            );
            $lock = $this->runs->acquireConversation($conversation);
            $this->runs->stage($runId, 'context');
            $this->runEvents->record($runId, 'lifecycle', 'info', 'context.resolve.started', 'started', [
                'requested_mode' => $requestedMode,
            ]);

            $materialPublicIds = $materialContextOrPublicIds instanceof ExpertChatMaterialContext
                ? []
                : $this->chat->requestMaterialPublicIds($conversation, $clientMessageId, $materialContextOrPublicIds);
            $fingerprint ??= $this->chat->requestFingerprint($content, $materialPublicIds, $requestedMode);
            $existingUser = $conversation->messages()->where('role', 'user')->where('metadata->client_message_id', $clientMessageId)->first();
            $persistedIds = $existingUser === null ? $materialPublicIds : $this->chat->persistedMaterialPublicIds($existingUser);
            $historicalIds = $this->chat->historicalMaterialPublicIds($conversation, $content, $existingUser, hasCurrentMaterials: $persistedIds !== []);
            $plan = $this->chat->contextPlan($conversation, $content, $persistedIds, $historicalIds, $existingUser);
            $this->runs->stage($runId, 'context', [
                'selected_material_count' => count($materialPublicIds),
                'persisted_material_count' => count($persistedIds),
                'resolved_material_count' => count($plan->resolvedMaterials),
                'active_material_count' => count($plan->activeMaterials),
                'metadata' => [
                    'scope' => $plan->scope,
                    'coverage_mode' => $plan->coverageMode,
                ],
            ]);
            $this->runEvents->record($runId, 'lifecycle', 'info', 'context.resolve.completed', 'completed', [
                'material_count' => count($materialPublicIds),
                'resolved_material_count' => count($plan->resolvedMaterials),
                'active_material_count' => count($plan->activeMaterials),
                'requested_mode' => $requestedMode,
            ]);
            if ($plan->diagnostics['requires_material_disambiguation'] ?? false) {
                throw ExpertMaterialContextException::ambiguousContext($plan->resolution?->ambiguousCandidates ?? []);
            }
            $this->chat->assertWorkloadExecutable(
                $conversation->project,
                $plan,
                $requestedMode,
                function (array $assessment) use ($runId, $requestedMode): void {
                    $this->recordWorkloadAssessment($runId, $requestedMode, $assessment);
                },
            );
            $userMessage = $this->chat->prepareStreamingUserMessage($conversation, $content, $clientMessageId, $fingerprint, $materialPublicIds, $requestedMode);
            $persistedMessageIds = $this->chat->persistedMaterialPublicIds($userMessage);
            $this->runs->stage($runId, 'materials', [
                'user_message_id' => (int) $userMessage->id,
                'selected_material_count' => count($materialPublicIds),
                'persisted_material_count' => count($persistedMessageIds),
                'resolved_material_count' => count($plan->resolvedMaterials),
                'active_material_count' => count($plan->activeMaterials),
            ]);
            $this->chat->recordContext($conversation, $userMessage, $plan);
            $existingAssistant = $this->chat->assistantReplyFor($conversation, $userMessage);
            if ($existingAssistant !== null) {
                $this->runs->stage($runId, 'materials', ['assistant_message_id' => (int) $existingAssistant->id]);
                $this->runs->setAssistantMessagePublicId($runId, $existingAssistant->public_id);
            }

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
                ExpertModeResolution::normalise($requestedMode),
            );
        } catch (\Throwable $exception) {
            $this->runs->finish(
                $runId,
                'failed',
                'error',
                $this->errorCode($exception) ?? $this->startErrorCode($exception),
                false,
                null,
                $exception::class,
                $startedAt,
            );
            $lock?->release();

            throw new ExpertChatRunStartFailure($runId, $exception);
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
        $requestedMode = ExpertModeResolution::normalise(is_array($userMessage->metadata) && is_string($userMessage->metadata['requested_mode'] ?? null) ? $userMessage->metadata['requested_mode'] : ExpertModeResolution::AUTO);
        $startedAt = microtime(true);
        $runId = (string) Str::uuid();
        $registryRun = null;
        $lock = null;

        try {
            $metadata = is_array($userMessage->metadata) ? $userMessage->metadata : [];
            $clientMessageId = is_string($metadata['client_message_id'] ?? null) ? $metadata['client_message_id'] : (string) Str::uuid();
            $registryRun = $this->runs->create(
                $conversation,
                $assistantMessage->public_id,
                $startedAt,
                $clientMessageId,
                $requestedMode,
                runId: $runId,
                userMessageId: (int) $userMessage->id,
            );
            $lock = $this->runs->acquireConversation($conversation);
            $this->runs->stage($runId, 'context', ['assistant_message_id' => (int) $assistantMessage->id]);
            $this->runEvents->record($runId, 'lifecycle', 'info', 'context.resolve.started', 'started', [
                'requested_mode' => $requestedMode,
            ]);

            $persistedPublicIds = $this->chat->persistedMaterialPublicIds($userMessage);
            $historicalIds = $this->chat->historicalMaterialPublicIds($conversation, $userMessage->content, $userMessage, hasCurrentMaterials: $persistedPublicIds !== []);
            $plan = $this->chat->contextPlan($conversation, $userMessage->content, $persistedPublicIds, $historicalIds, $userMessage);
            $this->runs->stage($runId, 'context', [
                'selected_material_count' => count($persistedPublicIds),
                'persisted_material_count' => count($persistedPublicIds),
                'resolved_material_count' => count($plan->resolvedMaterials),
                'active_material_count' => count($plan->activeMaterials),
                'metadata' => ['scope' => $plan->scope, 'coverage_mode' => $plan->coverageMode],
            ]);
            $this->runEvents->record($runId, 'lifecycle', 'info', 'context.resolve.completed', 'completed', [
                'material_count' => count($persistedPublicIds),
                'resolved_material_count' => count($plan->resolvedMaterials),
                'active_material_count' => count($plan->activeMaterials),
                'requested_mode' => $requestedMode,
            ]);
            $this->chat->assertWorkloadExecutable(
                $conversation->project,
                $plan,
                $requestedMode,
                function (array $assessment) use ($runId, $requestedMode): void {
                    $this->recordWorkloadAssessment($runId, $requestedMode, $assessment);
                },
            );
            $this->runs->stage($runId, 'materials');

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
                $requestedMode,
            );
        } catch (\Throwable $exception) {
            $this->runs->finish(
                $runId,
                'failed',
                'error',
                $this->errorCode($exception) ?? $this->startErrorCode($exception),
                false,
                null,
                $exception::class,
                $startedAt,
            );
            $lock?->release();

            throw new ExpertChatRunStartFailure($runId, $exception);
        }
    }

    /** @param \Closure(string, array<string, mixed>): void $emit */
    public function emit(ExpertChatStreamingRun $run, \Closure $emit, ?\Closure $clientDisconnected = null): void
    {
        $runId = $run->runId();
        $content = $run->existingAssistant?->content ?? '';
        $metadata = [];
        $executionPlan = null;
        $status = 'completed';
        $finishReason = 'stop';
        $errorCode = 'expert_stream_interrupted';
        $retryable = false;
        $hasVisibleOutput = false;
        $deltaSequence = 0;
        $reasoningSequence = 0;
        $startedAt = (float) ($run->registryRun['started_at'] ?? microtime(true));
        $firstDeltaAt = null;
        $lastHeartbeatAt = $startedAt;
        $assistant = null;
        $exceptionClass = null;
        $terminalEventStage = null;
        $upstreamProvider = null;
        $upstreamModel = null;
        $modelActivityId = null;
        /** @var array<string, string> $ocrActivityIds */
        $ocrActivityIds = [];

        $isCancellationRequested = function () use ($runId, $clientDisconnected): bool {
            if ($clientDisconnected !== null && $clientDisconnected()) {
                $this->runs->requestCancellation($runId);
            }

            return $this->runs->isCancellationRequested($runId);
        };
        $absoluteSeconds = max(1, (int) config('expert.streaming.absolute_timeout_seconds', 600));
        $tick = function () use ($startedAt, $absoluteSeconds, $emit, &$lastHeartbeatAt): void {
            if (microtime(true) - $startedAt >= $absoluteSeconds) {
                throw LLMProviderException::timeout('stream', $absoluteSeconds);
            }
            if (microtime(true) - $lastHeartbeatAt >= max(1, (int) config('expert.streaming.heartbeat_seconds', 15))) {
                $emit('heartbeat', ['version' => 1]);
                $lastHeartbeatAt = microtime(true);
            }
        };
        $token = new LLMCancellationToken($isCancellationRequested, $tick, $runId);
        $activity = new SseExpertRunActivitySink(
            $runId,
            $emit,
            $isCancellationRequested,
            $this->runEvents,
            fn (string $code) => $this->runs->recordActivity($runId, $code),
        );

        try {
            $this->runs->mark($runId, 'streaming');
            $this->runs->stage($runId, 'materials');
            $emit('run', [
                'version' => 1,
                'run_id' => $runId,
                'user_message' => $this->messagePayload($run->userMessage),
            ]);
            $activity->record('request.accepted', 'request');
        } catch (\Throwable $exception) {
            try {
                $this->runs->finish($runId, 'failed', 'error', 'expert_stream_interrupted', false, null, $exception::class, $startedAt);
            } finally {
                $run->lock->release();
            }
            throw $exception;
        }

        if ($run->existingAssistant !== null && ! $run->isContinuation) {
            $terminalized = false;
            try {
                $storedStatus = is_array($run->existingAssistant->metadata) ? ($run->existingAssistant->metadata['generation_status'] ?? 'completed') : 'completed';
                $event = in_array($storedStatus, ['stopped', 'interrupted'], true) ? 'cancelled' : 'done';
                $emit($event, [
                    'version' => 1,
                    'assistant_message' => $this->messagePayload($run->existingAssistant),
                    'finish_reason' => $storedStatus === 'completed' ? 'stop' : 'cancelled',
                ]);
                $this->runs->finish(
                    $runId,
                    $storedStatus === 'completed' ? 'completed' : 'cancelled',
                    $storedStatus === 'completed' ? 'stop' : 'cancelled',
                    startedAt: $startedAt,
                );
                $terminalized = true;
            } catch (\Throwable $exception) {
                $status = 'failed';
                $finishReason = 'error';
                $errorCode = $this->errorCode($exception) ?? 'expert_stream_finalize_failed';
                $retryable = false;
                $exceptionClass = $exception::class;

                throw $exception;
            } finally {
                try {
                    if (! $terminalized) {
                        $this->runs->finish($runId, 'failed', 'error', 'expert_stream_interrupted', false, null, null, $startedAt);
                    }
                } finally {
                    $run->lock->release();
                }
            }

            return;
        }

        try {
            $token->tick();
            $this->runs->stage($runId, 'materials');
            $historicalIds = $run->contextPack?->historicalMaterials ?? [];
            $materialBundle = $run->materialContext === null
                ? $this->materialContextBuilder->buildPartitioned(
                    $run->conversation->project,
                    $run->contextPack?->currentMaterials ?? $run->materialPublicIds,
                    $historicalIds,
                    $activity,
                    $run->contextPack === null ? [] : array_values(array_diff($run->contextPack->resolvedMaterials, $run->contextPack->currentMaterials, $run->contextPack->historicalMaterials)),
                    $run->contextPack,
                )
                : ExpertChatMaterialContextBundle::currentOnly($run->materialContext);
            $token->tick();
            $this->materialDiagnostics->log($runId, $materialBundle, $run->materialPublicIds, $historicalIds);
            $materialContext = $materialBundle->combined();
            $contextPack = $run->contextPack ?? $materialBundle->plan;
            $this->runs->stage($runId, 'materials', [
                'selected_material_count' => count($run->materialPublicIds),
                'persisted_material_count' => count($run->persistedMaterialPublicIds),
                'resolved_material_count' => count($contextPack?->resolvedMaterials ?? []),
                'active_material_count' => count($contextPack?->activeMaterials ?? []),
                'metadata' => [
                    'scope' => $contextPack?->scope,
                    'coverage_mode' => $contextPack?->coverageMode,
                ],
            ]);
            $executionPlan = $this->chat->executionPlan($run->requestedMode, $run->userMessage->content, $run->contextPack ?? $materialBundle->plan, $materialBundle);
            $metadata = $executionPlan->toMetadata();
            $this->runs->stage($runId, 'materials', [
                'resolved_mode' => $executionPlan->resolvedMode,
                'provider' => $executionPlan->provider,
                'model' => $executionPlan->model,
                'metadata' => $executionPlan->toMetadata(),
            ]);
            $this->materialDiagnostics->logWorkload($runId, $executionPlan);
            if ($executionPlan->requiresExecutionPipeline()) {
                throw match ($executionPlan->executionStrategy()) {
                    ExpertAnalysisExecutionStrategy::RETRIEVAL => ExpertMaterialContextException::retrievalPipelineRequired(),
                    ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT_EXHAUSTIVE => ExpertMaterialContextException::multiDocumentPipelineRequired($run->requestedMode),
                    default => ExpertMaterialContextException::multiDocumentRequired($run->requestedMode),
                };
            }

            $request = $run->isContinuation
                ? $this->chat->buildContinuationRequest($run->conversation, $run->userMessage, $run->existingAssistant, $materialBundle, $runId)
                : $this->chat->buildStreamingRequest($run->conversation, $run->userMessage, $materialBundle, $runId);
            $token->tick();

            foreach ($materialContext->ocrCandidates as $candidate) {
                $ocrActivityIds[strtolower($candidate->sha256)] = $activity->start(
                    $candidate->processingIntent === \App\Services\LLM\Enums\LLMFileProcessingIntent::PDF_TEXT_PARSE
                        ? 'pdf.text.started'
                        : 'pdf.ocr.started',
                    'material',
                    $candidate->name,
                );
            }
            $this->runs->stage($runId, 'provider', [
                'resolved_mode' => $executionPlan->resolvedMode,
                'provider' => $executionPlan->provider,
                'model' => $executionPlan->model,
                'metadata' => $executionPlan->toMetadata(),
            ]);
            $this->runEvents->record($runId, 'lifecycle', 'info', 'provider.selected', 'selected', [
                'requested_mode' => $executionPlan->requestedMode,
                'resolved_mode' => $executionPlan->resolvedMode,
                'provider' => $executionPlan->provider,
                'model' => $executionPlan->model,
            ]);
            $modelActivityId = $activity->start('model.request.started', 'model');

            foreach ($this->router->setUserId($run->conversation->project->user_id)->streamChat($request, $token, $runId, $executionPlan->routerProfile, $executionPlan->fallbackSelection) as $event) {
                $token->tick();
                if ($token->isCancellationRequested()) {
                    $terminalEventStage = $this->runs->currentStage($runId);
                    $status = 'stopped';
                    $finishReason = 'cancelled';
                    break;
                }
                if ($event->type === 'delta' && $event->text !== '') {
                    if ($firstDeltaAt === null) {
                        $firstDeltaAt = microtime(true);
                        $this->runs->stage($runId, 'streaming', ['first_token_at' => now()]);
                    }
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
                } elseif ($event->type === 'done') {
                    $metadata = [...$metadata, ...$event->metadata, 'tools_used' => $executionPlan->tools];
                    $upstreamProvider = is_string($event->metadata['actual_upstream_provider'] ?? null)
                        ? $event->metadata['actual_upstream_provider']
                        : (is_string($event->metadata['upstream_provider'] ?? null) ? $event->metadata['upstream_provider'] : null);
                    $upstreamModel = is_string($event->metadata['actual_upstream_model'] ?? null)
                        ? $event->metadata['actual_upstream_model']
                        : (is_string($event->metadata['upstream_model'] ?? null) ? $event->metadata['upstream_model'] : (is_string($event->metadata['model'] ?? null) ? $event->metadata['model'] : null));
                    $this->runs->stage($runId, 'provider', [
                        'upstream_provider' => $upstreamProvider,
                        'upstream_model' => $upstreamModel,
                    ]);
                    $this->persistOcrResults($materialBundle, $event->parsedFiles, $ocrActivityIds, $activity, $runId);
                } elseif ($event->type === 'heartbeat' || microtime(true) - $lastHeartbeatAt >= (int) config('expert.streaming.heartbeat_seconds', 15)) {
                    $emit('heartbeat', ['version' => 1]);
                    $lastHeartbeatAt = microtime(true);
                }
            }
            $token->tick();
            if ($token->isCancellationRequested()) {
                $terminalEventStage = $this->runs->currentStage($runId);
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
            $terminalEventStage = $this->runs->currentStage($runId);
            $status = 'stopped';
            $finishReason = 'cancelled';
        } catch (LLMProviderException|LLMChatUnavailableException $exception) {
            $terminalEventStage = $this->runs->currentStage($runId);
            $exceptionClass = $exception::class;
            $upstreamProvider = $this->upstreamProvider($exception);
            $status = $token->isCancellationRequested() ? 'stopped' : ($hasVisibleOutput || $content !== '' ? 'interrupted' : 'failed');
            $finishReason = $status === 'stopped' ? 'cancelled' : 'error';
            $errorCode = $this->errorCode($exception) ?? $errorCode;
            $retryable = $status !== 'stopped' && $this->isRetryableError($errorCode, $hasVisibleOutput);
            $this->logFailure($runId, $errorCode, $retryable, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt, $executionPlan);
        } catch (\Throwable $exception) {
            $terminalEventStage = $this->runs->currentStage($runId);
            $exceptionClass = $exception::class;
            $upstreamProvider = $this->upstreamProvider($exception);
            $status = $token->isCancellationRequested() ? 'stopped' : ($hasVisibleOutput || $content !== '' ? 'interrupted' : 'failed');
            $finishReason = $status === 'stopped' ? 'cancelled' : 'error';
            $errorCode = $this->errorCode($exception) ?? $errorCode;
            $retryable = $status !== 'stopped' && $this->isRetryableError($errorCode, $hasVisibleOutput);
            $this->logFailure($runId, $errorCode, $retryable, $exception, $activity->lastActivityCode(), $startedAt, $firstDeltaAt, $executionPlan);
        } finally {
            $terminalized = false;
            try {
                if ($status === 'completed' && $executionPlan !== null && isset($materialBundle)) {
                    $coveragePack = $run->contextPack ?? $materialBundle->plan;
                    if ($coveragePack !== null) {
                        $executionPlan = $executionPlan->withCoverage(
                            ExpertAnalysisCoverage::fromBundle($coveragePack, $materialBundle)->markAllProcessed(),
                        );
                        $metadata = [...$metadata, ...$executionPlan->toMetadata()];
                    }
                }
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
                $this->runs->stage($runId, 'persistence');
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
                    $this->runs->stage($runId, 'persistence', ['assistant_message_id' => (int) $assistant->id]);
                }
                if ($assistant !== null) {
                    $activity->record('response.persisted', 'response');
                }
                if ($status === 'stopped') {
                    $activity->record('generation.cancelled', 'generation');
                } elseif ($status === 'interrupted') {
                    $activity->record('generation.interrupted', 'generation');
                }
                $durableStatus = match ($status) {
                    'completed' => 'completed',
                    'stopped' => 'cancelled',
                    'interrupted' => 'interrupted',
                    default => 'failed',
                };
                $this->runs->finish(
                    $runId,
                    $durableStatus,
                    $finishReason,
                    in_array($durableStatus, ['failed', 'interrupted'], true) ? $errorCode : null,
                    $durableStatus === 'cancelled' ? false : $retryable,
                    $activity->lastActivityCode(),
                    $exceptionClass,
                    $startedAt,
                    [
                        ...($terminalEventStage === null ? [] : ['event_stage' => $terminalEventStage]),
                        'user_message_id' => (int) $run->userMessage->id,
                        'assistant_message_id' => $assistant === null ? null : (int) $assistant->id,
                        'resolved_mode' => $executionPlan?->resolvedMode,
                        'provider' => $executionPlan?->provider,
                        'model' => $executionPlan?->model,
                        'upstream_provider' => $upstreamProvider,
                        'upstream_model' => $upstreamModel,
                        'metadata' => $executionPlan?->toMetadata() ?? [],
                    ],
                );
                $terminalized = true;
            } catch (\Throwable $exception) {
                $terminalEventStage = $this->runs->currentStage($runId);
                $status = 'failed';
                $finishReason = 'error';
                $errorCode = $this->errorCode($exception) ?? 'expert_stream_finalize_failed';
                $retryable = false;
                $exceptionClass = $exception::class;

                throw $exception;
            } finally {
                try {
                    if (! $terminalized) {
                        $this->runs->finish(
                            $runId,
                            'failed',
                            'error',
                            $errorCode,
                            false,
                            $activity->lastActivityCode(),
                            $exceptionClass,
                            $startedAt,
                            $terminalEventStage === null ? [] : ['event_stage' => $terminalEventStage],
                        );
                    }
                } finally {
                    $run->lock->release();
                }
            }
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
                $this->identities->enrichPdfCandidate($candidate, $parsed->text);
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

    /** @param array<string, mixed> $assessment */
    private function recordWorkloadAssessment(string $runId, string $requestedMode, array $assessment): void
    {
        $strategy = is_string($assessment['execution_strategy'] ?? null)
            ? $assessment['execution_strategy']
            : ExpertAnalysisExecutionStrategy::DIRECT;
        $requiresPipeline = ExpertAnalysisExecutionStrategy::requiresPipeline($strategy);

        $this->runEvents->record(
            $runId,
            'lifecycle',
            $requiresPipeline ? 'warning' : 'info',
            'workload.assessed',
            $requiresPipeline ? 'rejected' : 'completed',
            [
                'execution_strategy' => $strategy,
                'material_count' => (int) ($assessment['material_count'] ?? 0),
                'requested_mode' => $requestedMode,
            ],
        );
    }

    private function errorCode(\Throwable $exception): ?string
    {
        if ($exception instanceof ExpertModelPolicyException) {
            return $exception->errorCode;
        }
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

    private function startErrorCode(\Throwable $exception): string
    {
        if ($exception instanceof ExpertChatRunInProgressException) {
            return 'expert_run_in_progress';
        }
        if ($exception instanceof ExpertChatRequestConflictException) {
            return 'expert_request_conflict';
        }
        if ($exception instanceof ExpertChatStreamException) {
            return 'expert_continue_not_allowed';
        }
        if ($exception instanceof LLMUnsupportedCapabilityException) {
            return match ($exception->capability) {
                LLMCapability::IMAGE_INPUT => 'vision_not_supported',
                LLMCapability::PDF_OCR => 'pdf_ocr_failed',
                LLMCapability::FILE_INPUT => 'material_not_supported',
                default => 'streaming_not_supported',
            };
        }

        return $this->errorCode($exception) ?? 'expert_stream_error';
    }

    private function upstreamProvider(\Throwable $exception): ?string
    {
        if ($exception instanceof LLMProviderException) {
            return $exception->getProvider();
        }
        if ($exception instanceof LLMChatUnavailableException && $exception->getPrevious() instanceof LLMProviderException) {
            return $exception->getPrevious()->getProvider();
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
            'multi_document_pipeline_required',
            'multi_document_required',
            'retrieval_pipeline_required',
            'expert_mode_unavailable',
            'expert_capability_unavailable',
        ], true);
    }

    private function logFailure(string $runId, string $code, bool $retryable, \Throwable $exception, ?string $activityCode, float $startedAt, ?float $firstDeltaAt, ?ExpertExecutionPlan $executionPlan = null): void
    {
        $rootException = $exception instanceof LLMChatUnavailableException && $exception->getPrevious() instanceof LLMProviderException
            ? $exception->getPrevious() : $exception;
        $profile = $this->profiles->effective($executionPlan?->routerProfile ?? LLMTaskProfileResolver::EXPERT_CHAT);
        $effectiveProvider = $profile['effective']['provider'] ?? null;
        $effectiveModel = $profile['effective']['model'] ?? null;
        $actualProvider = $rootException instanceof LLMProviderException ? $rootException->getProvider() : null;
        $safeProvider = static fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9_-]{1,40}$/i', $value) ? $value : null;
        $safeModel = static fn (mixed $value): ?string => is_string($value) && preg_match('/^[a-z0-9._\/-]{1,100}$/i', $value) ? $value : null;
        $httpStatus = $rootException instanceof LLMProviderException ? $rootException->getHttpStatus() : null;

        Log::warning('Expert chat stream failed.', [
            'run_id' => $runId,
            'task_profile' => $executionPlan?->profile ?? LLMTaskProfileResolver::EXPERT_CHAT,
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
            ...($executionPlan?->toMetadata() ?? []),
            'fallback_used' => $executionPlan?->fallbackSelection !== null,
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
