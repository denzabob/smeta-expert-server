<?php

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertMaterialService;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\OpenAiChatMessageMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpertChatAiFlowTest extends TestCase
{
    use RefreshDatabase;

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
        $payload = OpenAiChatMessageMapper::map($request);
        $this->assertSame(['system', 'user'], array_column($payload, 'role'));
        $this->assertStringContainsString('PROJECT CORE', $payload[0]['content']);
        $this->assertStringContainsString('Проанализируй ситуацию', json_encode($payload[1]['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('Название: Проект', json_encode($payload[1]['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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

        $history = array_slice(OpenAiChatMessageMapper::map($provider->chatRequests[0]), 1);
        $this->assertSame([
            ['role' => 'user', 'content' => 'Второй вопрос'],
            ['role' => 'assistant', 'content' => 'Второй ответ'],
        ], array_slice($history, 0, 2));
        $this->assertStringContainsString('Третий вопрос', $history[2]['content']);
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

    public function test_transport_retry_stays_inside_one_logical_request_without_duplicate_messages(): void
    {
        [$user, $conversation] = $this->conversation();
        $provider = new ExpertChatFakeProvider('Recovered assistant');
        $provider->failureOnce = LLMProviderException::httpError(
            'fake',
            429,
            'temporary upstream response',
        );
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Retry transport once'],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        )->assertCreated()->assertJsonPath('assistant_message.content', 'Recovered assistant');

        $this->assertCount(2, $provider->chatRequests);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_provider_secret_never_reaches_expert_response_or_application_log(): void
    {
        $secret = 'sk-provider-must-not-leak';
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });

        [$user, $conversation] = $this->conversation();
        $provider = new ExpertChatFakeProvider('');
        $provider->failure = new LLMProviderException(
            'Authorization: Bearer '.$secret,
            'fake',
            'network',
        );
        $this->installRouter($provider);

        $response = $this->actingAs($user, 'sanctum')->postJson(
            $this->messageUrl($conversation),
            ['content' => 'Do not leak provider secret'],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        );

        $response->assertStatus(503)->assertJsonPath('code', 'expert_chat_unavailable');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode($logged, JSON_THROW_ON_ERROR));
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
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

    public function test_message_attachments_persist_in_selected_order_and_history(): void
    {
        Storage::fake('local');
        [$user, $conversation] = $this->conversation();
        $first = $this->textMaterial($conversation, 'Первый.txt', 'Первый документ');
        $second = $this->textMaterial($conversation, 'Второй.txt', 'Второй документ');
        $provider = new ExpertChatFakeProvider('Ответ по документам');
        $this->installRouter($provider);

        $response = $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Сравни файлы',
            'material_public_ids' => [$second->public_id, $first->public_id],
        ], ['X-Expert-Message-Id' => (string) Str::uuid()]);

        $response->assertCreated()->assertJsonPath('user_message.attachments.0.material_public_id', $second->public_id)
            ->assertJsonPath('user_message.attachments.1.material_public_id', $first->public_id)
            ->assertJsonPath('assistant_message.attachments', []);
        $this->assertSame(2, $conversation->messages()->where('role', 'user')->sole()->attachments()->count());
        $this->assertCount(2, $provider->chatRequests[0]->materialContext);

        $this->actingAs($user, 'sanctum')->getJson($this->messageUrl($conversation))
            ->assertOk()->assertJsonPath('data.0.attachments.0.original_name', 'Второй.txt')
            ->assertJsonPath('data.0.attachments.1.original_name', 'Первый.txt');
    }

    public function test_retry_after_reload_uses_persisted_attachments_without_duplicates_or_context_change(): void
    {
        Storage::fake('local');
        [$user, $conversation] = $this->conversation();
        $first = $this->textMaterial($conversation, 'А.txt', 'Исходный факт А');
        $second = $this->textMaterial($conversation, 'Б.txt', 'Исходный факт Б');
        $third = $this->textMaterial($conversation, 'В.txt', 'Посторонний факт');
        $messageId = (string) Str::uuid();
        $provider = new ExpertChatFakeProvider('Повторный ответ');
        $provider->failure = LLMProviderException::timeout('fake', 1);
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Проанализируй', 'material_public_ids' => [$first->public_id, $second->public_id],
        ], ['X-Expert-Message-Id' => $messageId])->assertStatus(504);
        $this->actingAs($user, 'sanctum')->getJson($this->messageUrl($conversation))
            ->assertOk()->assertJsonCount(2, 'data.0.attachments');
        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Проанализируй', 'material_public_ids' => [$first->public_id, $third->public_id],
        ], ['X-Expert-Message-Id' => $messageId])->assertStatus(409)->assertJsonPath('code', 'expert_request_conflict');

        app(CircuitBreaker::class)->reset('openrouter');
        app(CircuitBreaker::class)->reset('deepseek');
        $provider->failure = null;
        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Проанализируй',
        ], ['X-Expert-Message-Id' => $messageId])->assertCreated();

        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame(2, $conversation->messages()->where('role', 'user')->sole()->attachments()->count());
        $this->assertSame([$first->public_id, $second->public_id], array_column($provider->chatRequests[array_key_last($provider->chatRequests)]->materialContext, 'public_id'));
    }

    public function test_deleted_material_remains_in_message_history_as_unavailable(): void
    {
        Storage::fake('local');
        [$user, $conversation] = $this->conversation();
        $material = $this->textMaterial($conversation, 'Удалённый.txt', 'Содержимое');
        $this->installRouter(new ExpertChatFakeProvider('Ответ'));
        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Прочитай', 'material_public_ids' => [$material->public_id],
        ], ['X-Expert-Message-Id' => (string) Str::uuid()])->assertCreated();

        app(ExpertMaterialService::class)->delete($material);
        $this->assertFalse(Storage::disk('local')->exists($material->storage_path));
        $this->assertDatabaseHas('expert_message_materials', [
            'material_public_id_snapshot' => $material->public_id,
            'expert_project_material_id' => null,
            'original_name_snapshot' => 'Удалённый.txt',
        ]);
        $this->actingAs($user, 'sanctum')->getJson($this->messageUrl($conversation))
            ->assertOk()->assertJsonPath('data.0.attachments.0.available', false)
            ->assertJsonPath('data.0.attachments.0.original_name', 'Удалённый.txt');
    }

    public function test_retry_with_reordered_public_ids_uses_original_attachment_order(): void
    {
        Storage::fake('local');
        [$user, $conversation] = $this->conversation();
        $first = $this->textMaterial($conversation, 'Первый.txt', 'Первый факт');
        $second = $this->textMaterial($conversation, 'Второй.txt', 'Второй факт');
        $messageId = (string) Str::uuid();
        $provider = new ExpertChatFakeProvider('Ответ');
        $provider->failure = LLMProviderException::timeout('fake', 1);
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Сравни', 'material_public_ids' => [$first->public_id, $second->public_id],
        ], ['X-Expert-Message-Id' => $messageId])->assertStatus(504);

        app(CircuitBreaker::class)->reset('openrouter');
        app(CircuitBreaker::class)->reset('deepseek');
        $provider->failure = null;
        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Сравни', 'material_public_ids' => [$second->public_id, $first->public_id],
        ], ['X-Expert-Message-Id' => $messageId])->assertCreated();

        $this->assertSame([$first->public_id, $second->public_id], array_column($provider->chatRequests[array_key_last($provider->chatRequests)]->materialContext, 'public_id'));
        $this->assertSame(2, $conversation->messages()->where('role', 'user')->sole()->attachments()->count());
    }

    public function test_material_from_another_project_is_rejected_before_message_creation(): void
    {
        Storage::fake('local');
        [$user, $conversation] = $this->conversation();
        [, $otherConversation] = $this->conversation();
        $foreignMaterial = $this->textMaterial($otherConversation, 'Чужой.txt', 'Чужие данные');
        $provider = new ExpertChatFakeProvider('Не должен вызываться');
        $this->installRouter($provider);

        $this->actingAs($user, 'sanctum')->postJson($this->messageUrl($conversation), [
            'content' => 'Прочитай', 'material_public_ids' => [$foreignMaterial->public_id],
        ], ['X-Expert-Message-Id' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame(0, $conversation->messages()->count());
        $this->assertSame([], $provider->chatRequests);
    }

    private function textMaterial(ExpertConversation $conversation, string $name, string $contents): ExpertProjectMaterial
    {
        $path = 'expert/'.$conversation->project->public_id.'/materials/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, $contents);

        return $conversation->project->materials()->create([
            'uploaded_by' => $conversation->project->user_id,
            'original_name' => $name,
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen($contents),
            'category' => 'document',
            'status' => 'uploaded',
        ]);
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

    public ?LLMProviderException $failureOnce = null;

    public function __construct(public string $reply) {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-chat-model';
    }

    public function capabilities(): array
    {
        return [LLMCapability::TEXT_INPUT];
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse
    {
        $this->decompositionCalls++;

        return new LLMResponse('fake', 'fake-model', '{"steps":[]}', ['steps' => []], 1, usedJsonMode: true);
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        $this->chatRequests[] = $request;

        if ($this->failureOnce !== null) {
            $failure = $this->failureOnce;
            $this->failureOnce = null;
            throw $failure;
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return new LLMChatResponse('fake', 'fake-chat-model', $this->reply, 1);
    }
}
