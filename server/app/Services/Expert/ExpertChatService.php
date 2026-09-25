<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertProject;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMTextContent;
use App\Services\LLM\LLMRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ExpertChatService
{
    public function __construct(
        private readonly LLMRouter $llmRouter,
        private readonly ExpertChatPrompt $prompt,
        private readonly ExpertPdfOcrCache $ocrCache,
        private readonly ExpertMaterialIdentityService $identities,
        private readonly ExpertMessageAttachments $attachments,
        private readonly ExpertHistoricalMaterialResolver $historicalMaterials,
        private readonly ExpertChatMaterialContextDiagnostics $materialDiagnostics,
        private readonly ExpertContextPlanner $contextPlanner,
        private readonly ExpertProjectCoreContextBuilder $coreContextBuilder,
        private readonly ExpertTaskIntentResolver $taskIntentResolver,
        private readonly ExpertTaskRequirementsResolver $requirementsResolver,
        private readonly ExpertWorkloadAssessor $workloadAssessor,
        private readonly ExpertToolPolicyResolver $toolPolicy,
        private readonly ExpertModePolicyResolver $modePolicy,
        private readonly ExpertModelPolicyResolver $modelPolicy,
        private readonly ExpertEvidenceContextValidator $evidenceValidator,
    ) {}

    /** @param list<string> $currentIds @param list<string> $historicalIds */
    public function contextPlan(ExpertConversation $conversation, string $content, array $currentIds, array $historicalIds, ?ExpertMessage $message = null): ExpertContextPack
    {
        $snapshot = is_array($message?->metadata) ? ($message->metadata['expert_context_snapshot'] ?? null) : null;
        if (is_array($snapshot)) {
            $fallbackCore = is_string($snapshot['project_core'] ?? null)
                ? ''
                : $this->coreContextBuilder->build($conversation->project->loadMissing('researchObjects'));

            return ExpertContextPack::fromSnapshot($fallbackCore, $snapshot);
        }

        $intent = $this->taskIntentResolver->resolveForConversation($conversation, $content, $currentIds, $historicalIds);

        return $this->contextPlanner->plan($conversation, $content, $currentIds, $historicalIds, $intent, $message);
    }

    public function recordContext(ExpertConversation $conversation, ExpertMessage $message, ExpertContextPack $pack): void
    {
        DB::transaction(function () use ($message, $pack): void {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            if (isset($metadata['expert_context_snapshot'])) {
                return;
            }
            $message->forceFill(['metadata' => [...$metadata, 'expert_context_snapshot' => $pack->snapshot($message->public_id)]])->save();
        });
    }

    /**
     * @param  list<string>  $materialPublicIds
     */
    public function requestFingerprint(string $content, array $materialPublicIds, string $mode = ExpertModeResolution::AUTO): string
    {
        $normalisedContent = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
        $normalisedMaterialIds = array_map(
            static fn (string $publicId): string => strtolower($publicId),
            $materialPublicIds,
        );
        sort($normalisedMaterialIds, SORT_STRING);

        return hash('sha256', json_encode(
            [$normalisedContent, $normalisedMaterialIds, ExpertModeResolution::normalise($mode)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @param list<string>|null $submittedIds @return list<string> */
    public function requestMaterialPublicIds(ExpertConversation $conversation, string $clientMessageId, ?array $submittedIds): array
    {
        if ($submittedIds !== null) {
            return $submittedIds;
        }

        $message = $this->findUserMessage($conversation, $clientMessageId);

        return $message === null ? [] : $this->attachments->publicIds($message);
    }

    /** @param list<string> $submittedIds @return list<string> */
    public function materialPublicIdsForExecution(ExpertConversation $conversation, string $clientMessageId, array $submittedIds): array
    {
        $message = $this->findUserMessage($conversation, $clientMessageId);

        return $message === null ? $submittedIds : $this->persistedMaterialPublicIds($message);
    }

    public function assertExistingMaterialsAvailable(ExpertConversation $conversation, string $clientMessageId): void
    {
        $message = $this->findUserMessage($conversation, $clientMessageId);
        if ($message === null) {
            return;
        }

        $ids = $this->attachments->publicIds($message);
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if ($ids === [] && ! $this->fingerprintMatchesEmptyRequest($message, $metadata)) {
            throw ExpertMaterialContextException::originalUnavailable();
        }

        $this->attachments->assertAvailable($message);
    }

    /** @return list<string> */
    public function persistedMaterialPublicIds(ExpertMessage $message): array
    {
        $this->attachments->assertAvailable($message);

        $ids = $this->attachments->publicIds($message);
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if ($ids === [] && ! $this->fingerprintMatchesEmptyRequest($message, $metadata)) {
            throw ExpertMaterialContextException::originalUnavailable();
        }

        return $ids;
    }

    /** @return list<string> */
    public function historicalMaterialPublicIds(
        ExpertConversation $conversation,
        string $content,
        ?ExpertMessage $current = null,
        ?string $clientMessageId = null,
        bool $hasCurrentMaterials = false,
    ): array {
        $current ??= $clientMessageId === null ? null : $this->findUserMessage($conversation, $clientMessageId);

        return $this->historicalMaterials->resolve($conversation, $content, $current, $hasCurrentMaterials);
    }

    public function completedReplyOrFail(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
        string $requestedMode = ExpertModeResolution::AUTO,
    ): ?ExpertChatResult {
        $userMessage = $this->findUserMessage($conversation, $clientMessageId);

        if ($userMessage === null) {
            return null;
        }

        $this->assertRequestMatches($userMessage, $content, $requestFingerprint, $requestedMode);
        $assistantMessage = $this->findAssistantMessage($conversation, $userMessage);

        return $assistantMessage === null
            ? null
            : new ExpertChatResult($userMessage, $assistantMessage, false);
    }

    public function reply(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
        ExpertChatMaterialContext|ExpertChatMaterialContextBundle $materialContext,
        array $materialPublicIds = [],
        array $historicalMaterialPublicIds = [],
        ?ExpertExecutionPlan $executionPlan = null,
        string $requestedMode = ExpertModeResolution::AUTO,
    ): ExpertChatResult {
        $lock = Cache::lock(
            "expert-chat:{$conversation->id}:{$clientMessageId}",
            (int) config('expert.chat.idempotency_lock_seconds', 900),
        );

        return $lock->block(
            (int) config('expert.chat.idempotency_wait_seconds', 5),
            function () use ($conversation, $content, $clientMessageId, $requestFingerprint, $materialContext, $materialPublicIds, $historicalMaterialPublicIds, $executionPlan, $requestedMode): ExpertChatResult {
                $userMessage = $this->findUserMessage($conversation, $clientMessageId);

                if ($userMessage !== null) {
                    $this->assertRequestMatches($userMessage, $content, $requestFingerprint, $requestedMode);
                    $this->attachments->assertAvailable($userMessage);
                } else {
                    $userMessage = $this->createUserMessage($conversation, $content, $clientMessageId, $requestFingerprint, $materialPublicIds, $requestedMode);
                }

                $assistantMessage = $this->findAssistantMessage($conversation, $userMessage);
                if ($assistantMessage !== null) {
                    return new ExpertChatResult($userMessage, $assistantMessage, false);
                }

                $bundle = $this->materialBundle($materialContext);
                if ($bundle->plan !== null) {
                    $this->recordContext($conversation, $userMessage, $bundle->plan);
                }
                $resolvedExecutionPlan = $executionPlan ?? $this->executionPlan($requestedMode, $content, $bundle->plan, $bundle);
                $runId = (string) Str::uuid();
                $this->materialDiagnostics->log($runId, $bundle, $materialPublicIds, $historicalMaterialPublicIds);
                $this->materialDiagnostics->logWorkload($runId, $resolvedExecutionPlan);
                $response = $this->llmRouter
                    ->setUserId($conversation->project->user_id)
                    ->chat($this->buildRequest($conversation, $userMessage, $bundle, $runId), taskProfile: $resolvedExecutionPlan->routerProfile, profileFallback: $resolvedExecutionPlan->fallbackSelection);
                foreach ($bundle->combined()->ocrCandidates as $candidate) {
                    $parsed = collect($response->parsedFiles)->first(fn ($file) => strtolower($file->sha256) === strtolower($candidate->sha256));
                    if ($parsed === null) {
                        throw ExpertPdfOcrException::failed();
                    }
                    $this->ocrCache->put($candidate, $parsed);
                    $this->identities->enrichPdfCandidate($candidate, $parsed->text);
                    $this->materialDiagnostics->logProviderPdf(
                        $runId,
                        $bundle,
                        $candidate,
                        mb_strlen($parsed->text, 'UTF-8'),
                    );
                }
                if ($bundle->plan !== null) {
                    $resolvedExecutionPlan = $resolvedExecutionPlan->withCoverage(
                        ExpertAnalysisCoverage::fromBundle($bundle->plan, $bundle)->markAllProcessed(),
                    );
                }

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $response->content,
                    'metadata' => [
                        'in_reply_to' => $userMessage->public_id,
                        'provider' => $response->provider,
                        'model' => $response->model,
                        'run_id' => $runId,
                        'service_tier' => is_string($response->metadata['service_tier'] ?? null) ? $response->metadata['service_tier'] : null,
                        'latency_ms' => $response->latencyMs,
                        ...$resolvedExecutionPlan->toMetadata(),
                        'fallback_used' => (bool) ($response->metadata['fallback_used'] ?? false) || $resolvedExecutionPlan->fallbackSelection !== null,
                        'fallback_reason' => $response->metadata['fallback_reason'] ?? ($resolvedExecutionPlan->fallbackSelection === null ? null : 'capability_mismatch'),
                        'tools_used' => $response->metadata['tools_used'] ?? $resolvedExecutionPlan->tools,
                        'actual_upstream_provider' => $response->metadata['upstream_provider'] ?? $response->provider,
                        'actual_upstream_model' => $response->metadata['upstream_model'] ?? $response->model,
                    ],
                ]);

                return new ExpertChatResult($userMessage, $assistantMessage, true);
            },
        );
    }

    /**
     * Creates (or verifies) the logical user message without creating an
     * assistant reply. The caller owns the conversation-level streaming lock.
     */
    public function prepareStreamingUserMessage(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
        array $materialPublicIds = [],
        string $requestedMode = ExpertModeResolution::AUTO,
    ): ExpertMessage {
        $userMessage = $this->findUserMessage($conversation, $clientMessageId);
        if ($userMessage !== null) {
            $this->assertRequestMatches($userMessage, $content, $requestFingerprint, $requestedMode);
            $this->attachments->assertAvailable($userMessage);

            return $userMessage;
        }

        return $this->createUserMessage($conversation, $content, $clientMessageId, $requestFingerprint, $materialPublicIds, $requestedMode);
    }

    public function assistantReplyFor(ExpertConversation $conversation, ExpertMessage $userMessage): ?ExpertMessage
    {
        return $this->findAssistantMessage($conversation, $userMessage);
    }

    public function assertStreamingSnapshot(ExpertMessage $userMessage, string $content, string $requestFingerprint, string $requestedMode = ExpertModeResolution::AUTO): void
    {
        $this->assertRequestMatches($userMessage, $content, $requestFingerprint, $requestedMode);
    }

    public function executionPlan(string $requestedMode, string $content, ?ExpertContextPack $pack, ExpertChatMaterialContextBundle $bundle): ExpertExecutionPlan
    {
        if ($pack === null) {
            throw ExpertModelPolicyException::profileUnavailable(ExpertModeResolution::normalise($requestedMode));
        }
        $requirements = $this->requirementsResolver->resolve($pack, $content, $bundle, $pack->intent);
        $requirements = $requirements
            ->withWorkload($this->workloadAssessor->assess($pack, $requirements, $bundle))
            ->withCoverage(ExpertAnalysisCoverage::fromBundle($pack, $bundle));
        $tools = $this->toolPolicy->resolve($requirements, $bundle);
        $mode = $this->modePolicy->resolve($requestedMode, $content, $requirements);

        return $this->modelPolicy->resolve($mode, $requirements, $tools);
    }

    public function assertWorkloadExecutable(ExpertProject $project, ExpertContextPack $pack, string $requestedMode): void
    {
        if ($pack->scope === 'project' && $pack->coverageMode === ExpertTaskIntent::EXHAUSTIVE) {
            throw ExpertMaterialContextException::retrievalPipelineRequired();
        }
        $assessment = $this->workloadAssessor->assessProject($project, $pack);
        if (! ExpertAnalysisExecutionStrategy::requiresPipeline($assessment->executionStrategy)) {
            return;
        }

        throw match ($assessment->executionStrategy) {
            ExpertAnalysisExecutionStrategy::RETRIEVAL => ExpertMaterialContextException::retrievalPipelineRequired(),
            ExpertAnalysisExecutionStrategy::MULTI_DOCUMENT_EXHAUSTIVE => ExpertMaterialContextException::multiDocumentPipelineRequired($requestedMode),
            default => ExpertMaterialContextException::multiDocumentRequired($requestedMode),
        };
    }

    public function buildStreamingRequest(
        ExpertConversation $conversation,
        ExpertMessage $currentMessage,
        ExpertChatMaterialContext|ExpertChatMaterialContextBundle $materialContext,
        ?string $runId = null,
    ): LLMChatRequest {
        return $this->buildRequest($conversation, $currentMessage, $materialContext, $runId);
    }

    public function buildContinuationRequest(
        ExpertConversation $conversation,
        ExpertMessage $userMessage,
        ExpertMessage $assistantMessage,
        ExpertChatMaterialContext|ExpertChatMaterialContextBundle $materialContext,
        ?string $runId = null,
    ): LLMChatRequest {
        $history = $conversation->messages()
            ->whereNotIn('id', [$userMessage->id, $assistantMessage->id])
            ->whereIn('role', ['user', 'assistant'])
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(0, (int) config('expert.chat.history_limit', 20)))
            ->with('attachments')
            ->get()
            ->map(fn (ExpertMessage $message): LLMChatMessage => $this->historyMessage($message))
            ->all();

        $bundle = $this->materialBundle($materialContext);
        $historyCount = count($history);
        $partial = LLMChatMessage::text('assistant', "CONVERSATION CONTEXT — PREVIOUS ASSISTANT MESSAGE (generated text, not source evidence):\n".$assistantMessage->content);
        // This instruction is internal transient context, never a persisted user message.
        $continue = LLMChatMessage::text('user', 'Продолжи предыдущий ответ с места остановки. Не повторяй уже сформированный текст.');

        return $this->groundedRequest($userMessage->content, $bundle, $history, $historyCount + 1, $runId, [$partial, $continue]);
    }

    /** @param array<string, mixed> $metadata */
    public function persistStreamingAssistant(
        ExpertConversation $conversation,
        ExpertMessage $userMessage,
        string $content,
        string $generationStatus,
        string $runId,
        string $finishReason,
        array $metadata = [],
        ?ExpertMessage $existingAssistant = null,
    ): ?ExpertMessage {
        if ($content === '') {
            return $existingAssistant;
        }

        $technicalMetadata = array_filter([
            'in_reply_to' => $userMessage->public_id,
            'generation_status' => $generationStatus,
            'run_id' => $runId,
            'finish_reason' => $finishReason,
            'provider' => is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null,
            'model' => is_string($metadata['model'] ?? null) ? $metadata['model'] : null,
            'upstream_id' => is_string($metadata['upstream_id'] ?? null) ? $metadata['upstream_id'] : null,
            'upstream_provider' => is_string($metadata['upstream_provider'] ?? null) ? $metadata['upstream_provider'] : null,
            'service_tier' => is_string($metadata['service_tier'] ?? null) ? $metadata['service_tier'] : null,
            'latency_ms' => is_int($metadata['latency_ms'] ?? null) ? $metadata['latency_ms'] : null,
        ], static fn (mixed $value): bool => $value !== null);
        foreach (['requested_mode', 'resolved_mode', 'route_reason', 'profile', 'task_profile', 'primary_provider', 'primary_model', 'selected_provider', 'selected_model', 'effective_provider', 'effective_model', 'required_capabilities', 'tools', 'fallback', 'material_count', 'current_material_count', 'active_material_count', 'scope', 'coverage_mode', 'requires_vision', 'requires_pdf_processing', 'requires_multi_document_pipeline', 'requires_retrieval_pipeline', 'requires_reasoning', 'requires_exhaustive_coverage', 'task_type', 'task_target', 'material_scope', 'cross_document', 'domain', 'intent_confidence', 'intent_resolver_source', 'intent_signals', 'execution_strategy', 'direct_context_allowed', 'strategy_reason', 'pipeline_stages', 'pdf_count', 'image_count', 'source_bytes', 'page_count', 'estimated_text_chars', 'prepared_payload_bytes', 'estimated_context_tokens', 'coverage_requested', 'coverage_processed', 'coverage_failed', 'coverage_skipped', 'coverage_complete', 'coverage_manifest', 'fallback_used', 'fallback_reason', 'tools_used', 'actual_upstream_provider', 'actual_upstream_model'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $technicalMetadata[$key] = $metadata[$key];
            }
        }
        $technicalMetadata['service_tier'] = is_string($metadata['service_tier'] ?? null) ? $metadata['service_tier'] : null;
        $technicalMetadata['fallback_used'] = (bool) ($metadata['fallback_used'] ?? false);

        if ($existingAssistant !== null) {
            $existingAssistant->forceFill([
                'content' => $content,
                'metadata' => [...(is_array($existingAssistant->metadata) ? $existingAssistant->metadata : []), ...$technicalMetadata],
            ])->save();

            return $existingAssistant->refresh();
        }

        return $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $content,
            'metadata' => $technicalMetadata,
        ]);
    }

    private function findUserMessage(ExpertConversation $conversation, string $clientMessageId): ?ExpertMessage
    {
        return $conversation->messages()
            ->where('role', 'user')
            ->where('metadata->client_message_id', $clientMessageId)
            ->first();
    }

    /** @param list<string> $materialPublicIds */
    private function createUserMessage(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
        array $materialPublicIds,
        string $requestedMode = ExpertModeResolution::AUTO,
    ): ExpertMessage {
        return DB::transaction(function () use ($conversation, $content, $clientMessageId, $requestFingerprint, $materialPublicIds, $requestedMode): ExpertMessage {
            $message = $conversation->messages()->create([
                'role' => 'user',
                'content' => $content,
                'metadata' => [
                    'client_message_id' => $clientMessageId,
                    'expert_request_fingerprint' => $requestFingerprint,
                    'requested_mode' => ExpertModeResolution::normalise($requestedMode),
                ],
            ]);
            $this->attachments->persist($conversation, $message, $materialPublicIds);

            return $message;
        });
    }

    private function findAssistantMessage(ExpertConversation $conversation, ExpertMessage $userMessage): ?ExpertMessage
    {
        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('metadata->in_reply_to', $userMessage->public_id)
            ->first();
    }

    private function assertRequestMatches(
        ExpertMessage $userMessage,
        string $content,
        string $requestFingerprint,
        string $requestedMode = ExpertModeResolution::AUTO,
    ): void {
        if (! hash_equals($userMessage->content, $content)) {
            throw new ExpertChatRequestConflictException(
                'Идентификатор сообщения уже использован с другим содержимым.',
            );
        }

        $metadata = is_array($userMessage->metadata) ? $userMessage->metadata : [];
        $storedMode = ExpertModeResolution::normalise(is_string($metadata['requested_mode'] ?? null) ? $metadata['requested_mode'] : ExpertModeResolution::AUTO);
        if ($storedMode !== ExpertModeResolution::normalise($requestedMode)) {
            throw new ExpertChatRequestConflictException(
                'Идентификатор сообщения уже использован с другим режимом AI.',
            );
        }
        $storedFingerprint = $metadata['expert_request_fingerprint'] ?? null;

        if (is_string($storedFingerprint) && $storedFingerprint !== '') {
            if (! hash_equals($storedFingerprint, $requestFingerprint)) {
                $legacyFingerprint = $this->legacyRequestFingerprint($userMessage->content, $this->attachments->publicIds($userMessage));
                if ($storedMode === ExpertModeResolution::AUTO && hash_equals($storedFingerprint, $legacyFingerprint)) {
                    $userMessage->forceFill([
                        'metadata' => [...$metadata, 'expert_request_fingerprint' => $requestFingerprint, 'requested_mode' => $storedMode],
                    ])->save();

                    return;
                }
                throw new ExpertChatRequestConflictException(
                    'Идентификатор сообщения уже использован с другим набором материалов.',
                );
            }

            return;
        }

        $legacyTextOnlyFingerprint = $this->requestFingerprint($userMessage->content, []);
        if (! hash_equals($legacyTextOnlyFingerprint, $requestFingerprint) && ! hash_equals($this->legacyRequestFingerprint($userMessage->content, []), $requestFingerprint)) {
            throw new ExpertChatRequestConflictException(
                'Невозможно подтвердить совпадение повторного запроса с исходным сообщением.',
            );
        }

        $userMessage->forceFill([
            'metadata' => [...$metadata, 'expert_request_fingerprint' => $requestFingerprint, 'requested_mode' => $storedMode],
        ])->save();
    }

    /** @param array<string, mixed> $metadata */
    private function fingerprintMatchesEmptyRequest(ExpertMessage $message, array $metadata): bool
    {
        $stored = $metadata['expert_request_fingerprint'] ?? null;
        if (! is_string($stored) || $stored === '') {
            return false;
        }
        $mode = ExpertModeResolution::normalise(is_string($metadata['requested_mode'] ?? null) ? $metadata['requested_mode'] : ExpertModeResolution::AUTO);

        return hash_equals($stored, $this->requestFingerprint($message->content, [], $mode))
            || hash_equals($stored, $this->legacyRequestFingerprint($message->content, []));
    }

    /** @param list<string> $materialPublicIds */
    private function legacyRequestFingerprint(string $content, array $materialPublicIds): string
    {
        $normalisedContent = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
        $normalisedMaterialIds = array_map(static fn (string $publicId): string => strtolower($publicId), $materialPublicIds);
        sort($normalisedMaterialIds, SORT_STRING);

        return hash('sha256', json_encode(
            [$normalisedContent, $normalisedMaterialIds],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function buildRequest(
        ExpertConversation $conversation,
        ExpertMessage $currentMessage,
        ExpertChatMaterialContext|ExpertChatMaterialContextBundle $materialContext,
        ?string $runId = null,
    ): LLMChatRequest {
        $history = $this->recentHistory($conversation, $currentMessage)
            ->map(fn (ExpertMessage $message): LLMChatMessage => $this->historyMessage($message))
            ->all();

        $bundle = $this->materialBundle($materialContext);

        return $this->groundedRequest($currentMessage->content, $bundle, $history, count($history), $runId);
    }

    private function materialBundle(ExpertChatMaterialContext|ExpertChatMaterialContextBundle $context): ExpertChatMaterialContextBundle
    {
        return $context instanceof ExpertChatMaterialContextBundle
            ? $context
            : ExpertChatMaterialContextBundle::currentOnly($context);
    }

    private function systemMessage(ExpertChatMaterialContextBundle $bundle): string
    {
        return $this->prompt->systemMessage().($bundle->plan === null ? '' : "\nCONTEXT SCOPE: ".$bundle->plan->scope.'; COVERAGE: '.$bundle->plan->coverageMode.'.');
    }

    /** @param list<LLMChatMessage> $history @param list<LLMChatMessage> $suffix */
    private function groundedRequest(string $content, ExpertChatMaterialContextBundle $bundle, array $history, int $historyCount, ?string $runId, array $suffix = []): LLMChatRequest
    {
        $sources = $this->evidenceValidator->prepare($bundle);
        $selectedIds = array_column($sources, 'id');
        $roles = array_column($sources, 'role', 'id');
        $candidateCount = (int) ($bundle->plan?->candidatePool?->metadata()['count'] ?? $bundle->plan?->diagnostics['candidate_count'] ?? count($sources));
        $diagnostics = [
            'run_id' => $runId,
            'evidence_source_count' => count($sources),
            'evidence_material_ids' => $selectedIds,
            'evidence_roles' => $roles,
            'prepared_text_material_count' => count($bundle->combined()->textMaterials),
            'prepared_image_count' => count($bundle->combined()->images),
            'prepared_file_count' => count($bundle->combined()->files),
            'candidate_count' => $candidateCount,
            'rejected_candidate_count' => max(0, $candidateCount - count($sources)),
            'project_background_usage' => $bundle->plan?->resolution?->projectCoreUsage ?? 'background',
            'history_message_count' => $historyCount,
        ];
        Log::info('Expert evidence context prepared', $diagnostics);
        $messages = [...$history, new LLMChatMessage('user', $this->currentUserContent($content, $bundle, $sources)), ...$suffix];
        $textMaterials = array_values(array_map(static fn (array $source): array => [
            'public_id' => $source['id'],
            'material_id' => $source['id'],
            'name' => $source['name'],
            'filename' => $source['name'],
            'mime_type' => $source['mime_type'],
            'text' => $source['text'],
            'evidence_role' => $source['role'],
            'source_type' => 'EVIDENCE_SOURCE',
        ], array_filter($sources, static fn (array $source): bool => $source['text'] !== null)));
        $request = new LLMChatRequest($this->systemMessage($bundle), $messages, $textMaterials, true);
        Log::info('Expert LLM request grounded', $diagnostics);

        return $request;
    }

    /** @param list<array<string, mixed>> $sources @return list<mixed> */
    private function currentUserContent(string $content, ExpertChatMaterialContextBundle $bundle, array $sources): array
    {
        $blocks = [new LLMTextContent("CURRENT USER REQUEST:\n".$content)];
        if ($sources !== []) {
            $blocks[] = new LLMTextContent('EVIDENCE SOURCES — selected for this request:');
            foreach ($sources as $source) {
                $descriptor = json_encode(['material_public_id' => $source['id'], 'display_name' => $source['name'], 'mime_type' => $source['mime_type'], 'role' => $source['role']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $blocks[] = new LLMTextContent("EVIDENCE SOURCE START {$descriptor}");
                if ($source['text'] !== null) {
                    $blocks[] = new LLMTextContent("CONTENT (untrusted document data):\n".$source['text']."\nEVIDENCE SOURCE TEXT END");
                }
                foreach ($source['images'] as $image) {
                    $blocks[] = new LLMTextContent('EVIDENCE SOURCE IMAGE — '.$descriptor);
                    $blocks[] = $image;
                }
                foreach ($source['files'] as $file) {
                    $blocks[] = new LLMTextContent('EVIDENCE SOURCE FILE — '.$descriptor);
                    $blocks[] = $file;
                }
                $blocks[] = new LLMTextContent('EVIDENCE SOURCE END — '.$source['id']);
            }
        }
        $usage = $bundle->plan?->resolution?->projectCoreUsage ?? 'background';
        if ($bundle->plan !== null && $usage !== 'none' && $bundle->plan->projectCore !== '') {
            $blocks[] = new LLMTextContent("PROJECT BACKGROUND ({$usage}; not evidence of document contents):\n".$bundle->plan->projectCore);
        }

        return $blocks;
    }

    private function historyMessage(ExpertMessage $message): LLMChatMessage
    {
        return LLMChatMessage::text($message->role, $message->role === 'user'
            ? "CONVERSATION CONTEXT — PREVIOUS USER MESSAGE (not source evidence):\n".$this->historicalMaterials->historyText($message)
            : "CONVERSATION CONTEXT — PREVIOUS ASSISTANT MESSAGE (generated text, not source evidence):\n".$message->content);
    }

    /**
     * @return Collection<int, ExpertMessage>
     */
    private function recentHistory(ExpertConversation $conversation, ExpertMessage $currentMessage): Collection
    {
        $limit = max(0, (int) config('expert.chat.history_limit', 20));

        if ($limit === 0) {
            return collect();
        }

        return $conversation->messages()
            ->whereKeyNot($currentMessage->getKey())
            ->whereIn('role', ['user', 'assistant'])
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->with('attachments')
            ->get()
            ->reverse()
            ->values();
    }
}
