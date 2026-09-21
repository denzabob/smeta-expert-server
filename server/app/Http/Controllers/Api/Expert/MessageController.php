<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MessageRequest;
use App\Http\Resources\Expert\MessageResource;
use App\Models\Expert\ExpertConversation;
use App\Services\Expert\ExpertChatMaterialContextBuilder;
use App\Services\Expert\ExpertChatRequestConflictException;
use App\Services\Expert\ExpertChatService;
use App\Services\Expert\ExpertMaterialContextException;
use App\Services\Expert\ExpertPdfOcrException;
use App\Services\Expert\ExpertVisionException;
use App\Services\LLM\Exceptions\LLMChatUnavailableException;
use App\Services\LLM\Exceptions\LLMUnsupportedCapabilityException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(
        private readonly ExpertChatService $expertChat,
        private readonly ExpertChatMaterialContextBuilder $materialContextBuilder,
    ) {}

    public function index(ExpertConversation $conversation)
    {
        $this->authorize('view', $conversation->project);

        return MessageResource::collection($conversation->messages()->with([
            'attachments.material',
            'feedback' => fn ($query) => $query->where('user_id', auth()->id()),
        ])->get());
    }

    public function store(MessageRequest $request, ExpertConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation->project);

        $content = $request->validated('content');
        $clientMessageId = $request->clientMessageId() ?? (string) Str::uuid();
        $materialPublicIds = $this->expertChat->requestMaterialPublicIds(
            $conversation,
            $clientMessageId,
            $request->exists('material_public_ids') ? $request->validated('material_public_ids', []) : null,
        );
        $requestFingerprint = $this->expertChat->requestFingerprint($content, $materialPublicIds);

        try {
            $existing = $this->expertChat->completedReplyOrFail(
                $conversation,
                $content,
                $clientMessageId,
                $requestFingerprint,
            );

            if ($existing !== null) {
                return response()->json([
                    'user_message' => new MessageResource($existing->userMessage),
                    'assistant_message' => new MessageResource($existing->assistantMessage),
                ]);
            }

            $this->expertChat->assertExistingMaterialsAvailable($conversation, $clientMessageId);
            $materialPublicIds = $this->expertChat->materialPublicIdsForExecution($conversation, $clientMessageId, $materialPublicIds);
            $historicalIds = $this->expertChat->historicalMaterialPublicIds(
                $conversation,
                $content,
                clientMessageId: $clientMessageId,
                hasCurrentMaterials: $materialPublicIds !== [],
            );
            $storedUser = $conversation->messages()->where('role', 'user')->where('metadata->client_message_id', $clientMessageId)->first();
            $plan = $this->expertChat->contextPlan($conversation, $content, $materialPublicIds, $historicalIds, $storedUser);
            if ($plan->diagnostics['requires_material_disambiguation'] ?? false) {
                throw ExpertMaterialContextException::ambiguousActiveMaterials();
            }
            if ($plan->requiresMultiDocumentPipeline) {
                throw ExpertMaterialContextException::multiDocumentPipelineRequired();
            }
            $materialContext = $this->materialContextBuilder->buildPartitioned(
                $conversation->project,
                $plan->currentMaterials,
                $plan->historicalMaterials,
                activeIds: array_values(array_diff($plan->resolvedMaterials, $plan->currentMaterials, $plan->historicalMaterials)),
                plan: $plan,
            );

            $result = $this->expertChat->reply(
                $conversation,
                $content,
                $clientMessageId,
                $requestFingerprint,
                $materialContext,
                $materialPublicIds,
                $historicalIds,
            );
        } catch (ExpertChatRequestConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'expert_request_conflict',
            ], 409);
        } catch (ExpertMaterialContextException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->status);
        } catch (ExpertPdfOcrException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->status);
        } catch (ExpertVisionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode,
            ], $exception->status);
        } catch (LLMUnsupportedCapabilityException $exception) {
            [$message, $code] = match ($exception->capability->value) {
                'image_input' => ['Не удалось обработать изображение. Повторите запрос.', 'vision_not_supported'],
                'pdf_ocr' => ['Не удалось обработать документ. Повторите запрос.', 'pdf_ocr_not_supported'],
                default => ['Не удалось обработать приложенный материал.', 'material_not_supported'],
            };

            return response()->json([
                'message' => $message,
                'code' => $code,
            ], 422);
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Сообщение с этим идентификатором ещё обрабатывается. Повторите попытку через несколько секунд.',
                'code' => 'expert_chat_request_in_progress',
            ], 409);
        } catch (LLMChatUnavailableException $exception) {
            $isTimeout = $exception->isTimeout();

            Log::warning('Expert Chat model response was unavailable.', [
                'conversation_public_id' => $conversation->public_id,
                'error_type' => $exception->lastErrorType()?->value,
                'failover_chain' => $exception->getFailoverChain(),
            ]);

            return response()->json([
                'message' => $isTimeout
                    ? 'Время ожидания ответа AI истекло. Повторите получение ответа.'
                    : 'Не удалось получить ответ AI. Повторите получение ответа.',
                'code' => $isTimeout ? 'expert_chat_timeout' : 'expert_chat_unavailable',
            ], $isTimeout ? 504 : 503);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Unexpected Expert Chat error.', [
                'conversation_public_id' => $conversation->public_id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Не удалось обработать сообщение. Повторите попытку позже.',
                'code' => 'expert_chat_error',
            ], 500);
        }

        return response()->json([
            'user_message' => new MessageResource($result->userMessage),
            'assistant_message' => new MessageResource($result->assistantMessage),
        ], $result->assistantWasCreated ? 201 : 200);
    }
}
