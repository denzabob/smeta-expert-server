<?php

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpertChatAiFlowTest extends TestCase
{
    use RefreshDatabase {
        migrateFreshUsing as protected defaultMigrateFreshUsing;
    }

    protected function migrateFreshUsing(): array
    {
        return array_merge($this->defaultMigrateFreshUsing(), [
            '--path' => [
                'database/migrations/2026_09_12_000000_create_expert_core_tables.php',
                'database/migrations/2026_09_12_000001_create_expert_storage_cleanup_tasks_table.php',
            ],
        ]);
    }

    public function test_message_reaches_provider_and_provider_response_is_saved_and_returned(): void
    {
        [$user, $conversation] = $this->conversation();
        $provider = new ExpertChatFakeProvider('Тестовый ответ модели');
        $this->installRouter($provider);
        $messageId = (string) Str::uuid();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Проанализируй ситуацию'],
            ['X-Expert-Message-Id' => $messageId],
        );

        $response->assertCreated()
            ->assertJsonPath('user_message.role', 'user')
            ->assertJsonPath('user_message.content', 'Проанализируй ситуацию')
            ->assertJsonPath('assistant_message.role', 'assistant')
            ->assertJsonPath('assistant_message.content', 'Тестовый ответ модели');

        $this->assertCount(1, $provider->chatRequests);
        $request = $provider->chatRequests[0];
        $this->assertSame([
            ['role' => 'system', 'content' => 'Ты помощник внутри экспертного проекта. Отвечай на основе текущего диалога. Не утверждай, что изучил материалы проекта, если они не были переданы тебе. Не выдумывай содержимое файлов.'],
            ['role' => 'user', 'content' => 'Проанализируй ситуацию'],
        ], $request->toProviderMessages());
        $this->assertDatabaseHas('expert_messages', ['expert_conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Тестовый ответ модели']);
    }

    public function test_history_is_ordered_and_limited_before_current_user_message(): void
    {
        config(['expert.chat.history_limit' => 2]);
        [$user, $conversation] = $this->conversation();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Первый вопрос']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Первый ответ']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Второй вопрос']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Второй ответ']);
        $provider = new ExpertChatFakeProvider('Третий ответ');
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Третий вопрос'],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        )->assertCreated();

        $this->assertSame([
            ['role' => 'user', 'content' => 'Второй вопрос'],
            ['role' => 'assistant', 'content' => 'Второй ответ'],
            ['role' => 'user', 'content' => 'Третий вопрос'],
        ], $provider->chatRequests[0]->messages);
    }

    public function test_provider_timeout_keeps_user_message_and_returns_controlled_error(): void
    {
        [$user, $conversation] = $this->conversation();
        $provider = new ExpertChatFakeProvider('');
        $provider->failure = LLMProviderException::timeout('fake', 1);
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Сохранить и дождаться ответа'],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        )->assertStatus(504)->assertJsonPath('code', 'expert_chat_timeout');

        $this->assertDatabaseHas('expert_messages', ['expert_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Сохранить и дождаться ответа']);
        $this->assertDatabaseMissing('expert_messages', ['expert_conversation_id' => $conversation->id, 'role' => 'assistant']);
    }

    public function test_provider_failure_returns_unavailable_error_without_creating_assistant(): void
    {
        [$user, $conversation] = $this->conversation();
        $provider = new ExpertChatFakeProvider('');
        $provider->failure = LLMProviderException::networkError('fake', 'connection refused');
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Сохранить при недоступном провайдере'],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        )->assertStatus(503)->assertJsonPath('code', 'expert_chat_unavailable');

        $this->assertDatabaseHas('expert_messages', ['expert_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'Сохранить при недоступном провайдере']);
        $this->assertDatabaseMissing('expert_messages', ['expert_conversation_id' => $conversation->id, 'role' => 'assistant']);
    }

    public function test_retry_reuses_saved_user_message_and_never_duplicates_assistant(): void
    {
        [$user, $conversation] = $this->conversation();
        $messageId = (string) Str::uuid();
        $failingProvider = new ExpertChatFakeProvider('');
        $failingProvider->failure = LLMProviderException::timeout('fake', 1);
        $this->installRouter($failingProvider);

        $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Повтори получение ответа'],
            ['X-Expert-Message-Id' => $messageId],
        )->assertStatus(504);

        app(CircuitBreaker::class)->reset('openrouter');
        app(CircuitBreaker::class)->reset('deepseek');
        $failingProvider->failure = null;
        $failingProvider->reply = 'Ответ после повтора';

        $retry = $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Повтори получение ответа'],
            ['X-Expert-Message-Id' => $messageId],
        );

        $retry->assertCreated()->assertJsonPath('assistant_message.content', 'Ответ после повтора');
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->where('content', 'Повтори получение ответа')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->where('content', 'Ответ после повтора')->count());

        $again = $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Повтори получение ответа'],
            ['X-Expert-Message-Id' => $messageId],
        );

        $again->assertOk()->assertJsonPath('assistant_message.content', 'Ответ после повтора');
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->where('content', 'Повтори получение ответа')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->where('content', 'Ответ после повтора')->count());
    }

    public function test_decomposition_capability_remains_separate_from_text_chat(): void
    {
        $provider = new ExpertChatFakeProvider('Не должен использоваться');
        $this->installRouter($provider);

        $response = app(LLMRouter::class)->generateDecomposition(new DecompositionPrompt(
            title: 'Демонтаж',
            hashableContext: [],
            desiredHours: null,
            schemaVersion: 1,
            systemPrompt: 'system',
            userPrompt: 'user',
            inputHash: 'test-hash',
        ));

        $this->assertSame(['steps' => []], $response->json);
        $this->assertSame(1, $provider->decompositionCalls);
        $this->assertCount(0, $provider->chatRequests);
    }

    private function installRouter(ExpertChatFakeProvider $provider): void
    {
        $router = new LLMRouter(
            app(CircuitBreaker::class),
            app(LLMSettingsRepository::class),
            app(LLMErrorClassifier::class),
            static fn (string $name, array $settings): LLMProviderInterface => $provider,
        );

        $this->app->instance(LLMRouter::class, $router);
    }

    /** @return array{User, ExpertConversation} */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $user->id, 'name' => 'Проект', 'domain' => 'other', 'work_type' => 'other']);

        return [$user, $project->conversations()->create(['title' => 'Общий анализ'])];
    }

    private function messageUrl(ExpertConversation $conversation): string
    {
        return "/api/expert/conversations/{$conversation->public_id}/messages";
    }
}

final class ExpertChatFakeProvider implements LLMProviderInterface
{
    /** @var list<LLMChatRequest> */
    public array $chatRequests = [];
    public int $decompositionCalls = 0;
    public ?LLMProviderException $failure = null;

    public function __construct(public string $reply) {}

    public function name(): string { return 'fake'; }
    public function supportsJsonMode(): bool { return true; }
    public function isAvailable(): bool { return true; }

    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse
    {
        $this->decompositionCalls++;
        return new LLMResponse('fake', 'fake-model', '{"steps":[]}', ['steps' => []], 1, usedJsonMode: true);
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        $this->chatRequests[] = $request;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new LLMChatResponse('fake', 'fake-chat-model', $this->reply, 1);
    }
}
