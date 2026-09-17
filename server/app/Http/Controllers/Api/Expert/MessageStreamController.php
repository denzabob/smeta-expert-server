<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MessageRequest;
use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Services\Expert\ExpertChatRequestConflictException;
use App\Services\Expert\ExpertChatRunInProgressException;
use App\Services\Expert\ExpertChatRunRegistry;
use App\Services\Expert\ExpertChatStreamException;
use App\Services\Expert\ExpertChatStreamingRun;
use App\Services\Expert\ExpertChatStreamingService;
use App\Services\Expert\ExpertMaterialContextException;
use App\Services\Expert\ExpertPdfOcrException;
use App\Services\Expert\ExpertVisionException;
use App\Services\LLM\Exceptions\LLMUnsupportedCapabilityException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MessageStreamController extends Controller
{
    public function __construct(
        private readonly ExpertChatStreamingService $streaming,
        private readonly ExpertChatRunRegistry $runs,
        private readonly \App\Services\Expert\ExpertChatService $chat,
    ) {}

    public function store(MessageRequest $request, ExpertConversation $conversation): StreamedResponse|JsonResponse
    {
        $this->authorize('update', $conversation->project);
        $content = $request->validated('content');
        $clientMessageId = $request->clientMessageId() ?? (string) Str::uuid();
        $materialPublicIds = $request->validated('material_public_ids', []);

        try {
            $run = $this->streaming->start(
                $conversation,
                $content,
                $clientMessageId,
                $this->chat->requestFingerprint($content, $materialPublicIds),
                $materialPublicIds,
            );
        } catch (\Throwable $exception) {
            return $this->startError($exception);
        }

        return $this->response($run);
    }

    public function continue(MessageRequest $request, ExpertConversation $conversation, ExpertMessage $assistant): StreamedResponse|JsonResponse
    {
        $this->authorize('update', $conversation->project);
        if ((int) $assistant->expert_conversation_id !== (int) $conversation->id) {
            abort(404);
        }
        $content = $request->validated('content');
        $materialPublicIds = $request->validated('material_public_ids', []);
        try {
            $run = $this->streaming->continueRun(
                $conversation,
                $assistant,
                $content,
                $this->chat->requestFingerprint($content, $materialPublicIds),
                $materialPublicIds,
            );
        } catch (ExpertChatRequestConflictException) {
            return response()->json(['message' => 'Исходный запрос изменён.', 'code' => 'expert_continue_snapshot_conflict'], 409);
        } catch (\Throwable $exception) {
            return $this->startError($exception);
        }

        return $this->response($run);
    }

    public function cancel(ExpertConversation $conversation, string $runId): JsonResponse
    {
        $this->authorize('update', $conversation->project);
        $run = $this->runs->find($runId);
        if ($run === null || ($run['conversation_public_id'] ?? null) !== $conversation->public_id || (int) ($run['user_id'] ?? 0) !== (int) $conversation->project->user_id) {
            abort(404);
        }
        $run = $this->runs->requestCancellation($runId);

        return response()->json(['run_id' => $runId, 'accepted' => true, 'status' => $run['status'] ?? 'stopping']);
    }

    private function response(ExpertChatStreamingRun $run): StreamedResponse
    {
        return response()->stream(function () use ($run): void {
            ignore_user_abort(true);
            $emit = static function (string $event, array $payload): void {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };
            $this->streaming->emit($run, $emit, static fn (): bool => connection_aborted() === 1);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function startError(\Throwable $exception): JsonResponse
    {
        if ($exception instanceof ExpertChatRunInProgressException) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'expert_run_in_progress'], 409);
        }
        if ($exception instanceof ExpertChatRequestConflictException) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'expert_request_conflict'], 409);
        }
        if ($exception instanceof ExpertChatStreamException) {
            return response()->json(['message' => $exception->getMessage(), 'code' => 'expert_continue_not_allowed'], 422);
        }
        if ($exception instanceof ExpertMaterialContextException || $exception instanceof ExpertPdfOcrException || $exception instanceof ExpertVisionException) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode], $exception->status);
        }
        if ($exception instanceof LLMUnsupportedCapabilityException) {
            return response()->json(['message' => 'Текущая модель AI не поддерживает потоковый режим.', 'code' => 'streaming_not_supported'], 422);
        }
        report($exception);

        return response()->json(['message' => 'Не удалось начать потоковый ответ AI.', 'code' => 'expert_stream_error'], 500);
    }
}
