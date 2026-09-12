<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\LLMRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class ExpertChatService
{
    public function __construct(
        private readonly LLMRouter $llmRouter,
        private readonly ExpertChatPrompt $prompt,
    ) {
    }

    /**
     * The existing metadata column keeps the client request ID and the assistant reply link.
     * It is deliberately unrelated to material context and does not introduce a new entity.
     */
    public function reply(ExpertConversation $conversation, string $content, string $clientMessageId): ExpertChatResult
    {
        $lock = Cache::lock(
            "expert-chat:{$conversation->id}:{$clientMessageId}",
            (int) config('expert.chat.idempotency_lock_seconds', 900),
        );

        return $lock->block(
            (int) config('expert.chat.idempotency_wait_seconds', 5),
            function () use ($conversation, $content, $clientMessageId): ExpertChatResult {
                $userMessage = $conversation->messages()
                    ->where('role', 'user')
                    ->where('metadata->client_message_id', $clientMessageId)
                    ->first();

                if ($userMessage !== null && !hash_equals($userMessage->content, $content)) {
                    throw ValidationException::withMessages([
                        'content' => ['Идентификатор сообщения уже использован с другим содержимым.'],
                    ]);
                }

                if ($userMessage === null) {
                    $userMessage = $conversation->messages()->create([
                        'role' => 'user',
                        'content' => $content,
                        'metadata' => ['client_message_id' => $clientMessageId],
                    ]);
                }

                $assistantMessage = $conversation->messages()
                    ->where('role', 'assistant')
                    ->where('metadata->in_reply_to', $userMessage->public_id)
                    ->first();

                if ($assistantMessage !== null) {
                    return new ExpertChatResult($userMessage, $assistantMessage, false);
                }

                $response = $this->llmRouter
                    ->setUserId($conversation->project->user_id)
                    ->chat($this->buildRequest($conversation, $userMessage));

                $assistantMessage = $conversation->messages()->create([
                    'role' => 'assistant',
                    'content' => $response->content,
                    'metadata' => ['in_reply_to' => $userMessage->public_id],
                ]);

                return new ExpertChatResult($userMessage, $assistantMessage, true);
            },
        );
    }

    private function buildRequest(ExpertConversation $conversation, ExpertMessage $currentMessage): LLMChatRequest
    {
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

        return new LLMChatRequest($this->prompt->systemMessage(), $history);
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
