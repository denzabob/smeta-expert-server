<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\LLMRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class ExpertChatService
{
    public function __construct(
        private readonly LLMRouter $llmRouter,
        private readonly ExpertChatPrompt $prompt,
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

    /**
     * @param list<array{public_id: string, name: string, mime_type: string, text: string}> $materialContext
     */
    public function reply(
        ExpertConversation $conversation,
        string $content,
        string $clientMessageId,
        string $requestFingerprint,
        array $materialContext,
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

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $response->content,
                    'metadata' => ['in_reply_to' => $userMessage->public_id],
                ]);

                return new ExpertChatResult($userMessage, $assistantMessage, true);
            },
        );
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

    /**
     * @param list<array{public_id: string, name: string, mime_type: string, text: string}> $materialContext
     */
    private function buildRequest(
        ExpertConversation $conversation,
        ExpertMessage $currentMessage,
        array $materialContext,
    ): LLMChatRequest {
        $history = $this->recentHistory($conversation, $currentMessage)
            ->map(fn (ExpertMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();

        $history[] = [
            'role' => 'user',
            'content' => $currentMessage->content,
        ];

        return new LLMChatRequest($this->prompt->systemMessage(), $history, $materialContext);
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
