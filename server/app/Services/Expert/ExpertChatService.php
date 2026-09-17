<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMTextContent;
use App\Services\LLM\LLMRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class ExpertChatService
{
    public function __construct(
        private readonly LLMRouter $llmRouter,
        private readonly ExpertChatPrompt $prompt,
        private readonly ExpertPdfOcrCache $ocrCache,
    ) {
    }

    /**
     * @param list<string> $materialPublicIds
     */
    public function requestFingerprint(string $content, array $materialPublicIds): string
    {
        $normalisedContent = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
        $normalisedMaterialIds = array_map(
            static fn (string $publicId): string => strtolower($publicId),
            $materialPublicIds,
        );
        sort($normalisedMaterialIds, SORT_STRING);

        return hash('sha256', json_encode(
            [$normalisedContent, $normalisedMaterialIds],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    public function completedReplyOrFail(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
    ): ?ExpertChatResult {
        $userMessage = $this->findUserMessage($conversation, $clientMessageId);

        if ($userMessage === null) {
            return null;
        }

        $this->assertRequestMatches($userMessage, $content, $requestFingerprint);
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
        ExpertChatMaterialContext $materialContext,
    ): ExpertChatResult {
        $lock = Cache::lock(
            "expert-chat:{$conversation->id}:{$clientMessageId}",
            (int) config('expert.chat.idempotency_lock_seconds', 900),
        );

        return $lock->block(
            (int) config('expert.chat.idempotency_wait_seconds', 5),
            function () use ($conversation, $content, $clientMessageId, $requestFingerprint, $materialContext): ExpertChatResult {
                $userMessage = $this->findUserMessage($conversation, $clientMessageId);

                if ($userMessage !== null) {
                    $this->assertRequestMatches($userMessage, $content, $requestFingerprint);
                } else {
                    $userMessage = $conversation->messages()->create([
                        'role' => 'user',
                        'content' => $content,
                        'metadata' => [
                            'client_message_id' => $clientMessageId,
                            'expert_request_fingerprint' => $requestFingerprint,
                        ],
                    ]);
                }

                $assistantMessage = $this->findAssistantMessage($conversation, $userMessage);
                if ($assistantMessage !== null) {
                    return new ExpertChatResult($userMessage, $assistantMessage, false);
                }

                $response = $this->llmRouter
                    ->setUserId($conversation->project->user_id)
                    ->chat($this->buildRequest($conversation, $userMessage, $materialContext));
                foreach ($materialContext->ocrCandidates as $candidate) {
                    $parsed = collect($response->parsedFiles)->first(fn ($file) => strtolower($file->sha256) === strtolower($candidate->sha256));
                    if ($parsed === null) throw ExpertPdfOcrException::failed();
                    $this->ocrCache->put($candidate, $parsed);
                }

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $response->content,
                    'metadata' => ['in_reply_to' => $userMessage->public_id],
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
    ): ExpertMessage {
        $userMessage = $this->findUserMessage($conversation, $clientMessageId);
        if ($userMessage !== null) {
            $this->assertRequestMatches($userMessage, $content, $requestFingerprint);
            return $userMessage;
        }

        return $conversation->messages()->create([
            'role' => 'user',
            'content' => $content,
            'metadata' => [
                'client_message_id' => $clientMessageId,
                'expert_request_fingerprint' => $requestFingerprint,
            ],
        ]);
    }

    public function assistantReplyFor(ExpertConversation $conversation, ExpertMessage $userMessage): ?ExpertMessage
    {
        return $this->findAssistantMessage($conversation, $userMessage);
    }

    public function assertStreamingSnapshot(ExpertMessage $userMessage, string $content, string $requestFingerprint): void
    {
        $this->assertRequestMatches($userMessage, $content, $requestFingerprint);
    }

    public function buildStreamingRequest(
        ExpertConversation $conversation,
        ExpertMessage $currentMessage,
        ExpertChatMaterialContext $materialContext,
    ): LLMChatRequest {
        return $this->buildRequest($conversation, $currentMessage, $materialContext);
    }

    public function buildContinuationRequest(
        ExpertConversation $conversation,
        ExpertMessage $userMessage,
        ExpertMessage $assistantMessage,
        ExpertChatMaterialContext $materialContext,
    ): LLMChatRequest {
        $history = $conversation->messages()
            ->whereNotIn('id', [$userMessage->id, $assistantMessage->id])
            ->whereIn('role', ['user', 'assistant'])
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(max(0, (int) config('expert.chat.history_limit', 20)))
            ->get()
            ->map(fn (ExpertMessage $message): LLMChatMessage => LLMChatMessage::text($message->role, $message->content))
            ->all();

        $history[] = new LLMChatMessage('user', [
            new LLMTextContent($userMessage->content),
            ...$materialContext->images,
            ...$materialContext->files,
        ]);
        $history[] = LLMChatMessage::text('assistant', $assistantMessage->content);
        // This instruction is internal transient context, never a persisted user message.
        $history[] = LLMChatMessage::text('user', 'Продолжи предыдущий ответ с места остановки. Не повторяй уже сформированный текст.');

        return new LLMChatRequest($this->prompt->systemMessage(), $history, $materialContext->textMaterials);
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
            'upstream_id' => is_string($metadata['upstream_id'] ?? null) ? $metadata['upstream_id'] : null,
            'upstream_provider' => is_string($metadata['provider'] ?? null) ? $metadata['provider'] : null,
            'service_tier' => is_string($metadata['service_tier'] ?? null) ? $metadata['service_tier'] : null,
        ], static fn (mixed $value): bool => $value !== null);

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
    ): void {
        if (!hash_equals($userMessage->content, $content)) {
            throw new ExpertChatRequestConflictException(
                'Идентификатор сообщения уже использован с другим содержимым.',
            );
        }

        $metadata = is_array($userMessage->metadata) ? $userMessage->metadata : [];
        $storedFingerprint = $metadata['expert_request_fingerprint'] ?? null;

        if (is_string($storedFingerprint) && $storedFingerprint !== '') {
            if (!hash_equals($storedFingerprint, $requestFingerprint)) {
                throw new ExpertChatRequestConflictException(
                    'Идентификатор сообщения уже использован с другим набором материалов.',
                );
            }

            return;
        }

        $legacyTextOnlyFingerprint = $this->requestFingerprint($userMessage->content, []);
        if (!hash_equals($legacyTextOnlyFingerprint, $requestFingerprint)) {
            throw new ExpertChatRequestConflictException(
                'Невозможно подтвердить совпадение повторного запроса с исходным сообщением.',
            );
        }

        $userMessage->forceFill([
            'metadata' => [...$metadata, 'expert_request_fingerprint' => $requestFingerprint],
        ])->save();
    }

    private function buildRequest(
        ExpertConversation $conversation,
        ExpertMessage $currentMessage,
        ExpertChatMaterialContext $materialContext,
    ): LLMChatRequest {
        $history = $this->recentHistory($conversation, $currentMessage)
            ->map(fn (ExpertMessage $message): LLMChatMessage => LLMChatMessage::text(
                $message->role,
                $message->content,
            ))
            ->all();

        $history[] = new LLMChatMessage('user', [
            new LLMTextContent($currentMessage->content),
            ...$materialContext->images,
            ...$materialContext->files,
        ]);

        return new LLMChatRequest($this->prompt->systemMessage(), $history, $materialContext->textMaterials);
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
            ->get()
            ->reverse()
            ->values();
    }
}
