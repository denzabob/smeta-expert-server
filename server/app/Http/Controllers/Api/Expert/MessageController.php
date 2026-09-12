<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MessageRequest;
use App\Http\Resources\Expert\MessageResource;
use App\Models\Expert\ExpertConversation;
use App\Services\Expert\ExpertChatService;
use App\Services\LLM\Exceptions\LLMChatUnavailableException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(private readonly ExpertChatService $expertChat) {}

    public function index(ExpertConversation $conversation){$this->authorize('view',$conversation->project);return MessageResource::collection($conversation->messages);}

    public function store(MessageRequest $request, ExpertConversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation->project);

        try {
            $result = $this->expertChat->reply(
                $conversation,
                $request->validated('content'),
                $request->clientMessageId() ?? (string) Str::uuid(),
            );
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
