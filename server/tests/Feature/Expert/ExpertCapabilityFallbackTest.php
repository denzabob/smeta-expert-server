<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertChatService;
use App\Services\Expert\ExpertChatStreamingService;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpertCapabilityFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::forget('llm:routerai:model_catalog:v1');
        app(LLMSettingsRepository::class)->saveFromAdmin([
            'mode' => 'manual',
            'primary_provider' => 'routerai',
            'providers' => ['routerai' => [
                'api_key' => 'test-router-key',
                'model' => 'custom/text-only',
                'base_url' => 'https://routerai.test/api/v1',
            ]],
        ]);
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only');
        $this->profile(LLMTaskProfileResolver::EXPERT_DEEP, 'custom/text-only');
    }

    public function test_primary_supports_capability(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'openai/gpt-4o', true, 'custom/text-only');
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'fast')->assertCreated()
            ->assertJsonPath('assistant_message.metadata.primary_model', 'openai/gpt-4o')
            ->assertJsonPath('assistant_message.metadata.selected_model', 'openai/gpt-4o')
            ->assertJsonPath('assistant_message.metadata.fallback_used', false);
        $this->assertChatModel('openai/gpt-4o');
    }

    public function test_primary_mismatch_uses_profile_fallback(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'openai/gpt-4o');
        [$user, $conversation, $images] = $this->conversationWithImages(3);
        $this->fakeAnswer();

        $response = $this->send($user, $conversation, $images, 'fast')->assertCreated()
            ->assertJsonPath('assistant_message.content', 'Тестовый ответ по изображениям.')
            ->assertJsonPath('assistant_message.metadata.requested_mode', 'fast')
            ->assertJsonPath('assistant_message.metadata.resolved_mode', 'fast')
            ->assertJsonPath('assistant_message.metadata.task_profile', 'expert_fast')
            ->assertJsonPath('assistant_message.metadata.primary_provider', 'routerai')
            ->assertJsonPath('assistant_message.metadata.primary_model', 'custom/text-only')
            ->assertJsonPath('assistant_message.metadata.selected_provider', 'routerai')
            ->assertJsonPath('assistant_message.metadata.selected_model', 'openai/gpt-4o')
            ->assertJsonPath('assistant_message.metadata.fallback_used', true)
            ->assertJsonPath('assistant_message.metadata.fallback_reason', 'capability_mismatch');
        $this->assertEqualsCanonicalizing(['text_input', 'image_input'], $response->json('assistant_message.metadata.required_capabilities'));
        $this->assertChatModel('openai/gpt-4o', 3);
    }

    public function test_incompatible_fallback_returns_controlled_error(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'custom/also-text-only');
        $this->assertCapabilityError();
    }

    public function test_disabled_fallback_returns_controlled_error(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', false, 'openai/gpt-4o');
        $this->assertCapabilityError();
    }

    public function test_all_required_capabilities_must_match(): void
    {
        config(['services.routerai.capability_overrides.custom/image-only' => ['text_input' => false, 'image_input' => true]]);
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'custom/image-only');
        $this->assertCapabilityError();
    }

    public function test_fast_fallback_does_not_promote_to_deep(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'openai/gpt-4o');
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'fast', 'Проанализируй изображение')->assertCreated()
            ->assertJsonPath('assistant_message.metadata.requested_mode', 'fast')
            ->assertJsonPath('assistant_message.metadata.resolved_mode', 'fast')
            ->assertJsonPath('assistant_message.metadata.selected_model', 'openai/gpt-4o');
        $this->assertChatModel('openai/gpt-4o');
    }

    public function test_deep_fallback_remains_deep(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_DEEP, 'custom/text-only', true, 'openai/gpt-4o');
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'deep')->assertCreated()
            ->assertJsonPath('assistant_message.metadata.requested_mode', 'deep')
            ->assertJsonPath('assistant_message.metadata.resolved_mode', 'deep')
            ->assertJsonPath('assistant_message.metadata.task_profile', 'expert_deep')
            ->assertJsonPath('assistant_message.metadata.fallback_used', true);
        $this->assertChatModel('openai/gpt-4o');
    }

    public function test_auto_fast_uses_fast_fallback(): void
    {
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'openai/gpt-4o');
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'auto')->assertCreated()
            ->assertJsonPath('assistant_message.metadata.requested_mode', 'auto')
            ->assertJsonPath('assistant_message.metadata.resolved_mode', 'fast')
            ->assertJsonPath('assistant_message.metadata.task_profile', 'expert_fast')
            ->assertJsonPath('assistant_message.metadata.fallback_used', true);
        $this->assertChatModel('openai/gpt-4o');
    }

    public function test_selected_fallback_is_actually_used_by_transport(): void
    {
        app(LLMSettingsRepository::class)->saveTaskProfile(LLMTaskProfileResolver::EXPERT_FAST, [
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'enabled' => true,
            'fallback_policy' => 'none',
            'fallback_enabled' => true,
            'fallback_provider' => 'routerai',
            'fallback_model' => 'openai/gpt-4o',
        ]);
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'fast')->assertCreated()
            ->assertJsonPath('assistant_message.metadata.primary_provider', 'deepseek')
            ->assertJsonPath('assistant_message.metadata.selected_provider', 'routerai');
        $this->assertChatModel('openai/gpt-4o');
        Http::assertNotSent(fn (ClientRequest $request): bool => $request->url() === 'https://routerai.test/api/v1/chat/completions'
            && ($request->data()['model'] ?? null) === 'custom/text-only');
    }

    public function test_streaming_route_uses_the_same_selected_fallback(): void
    {
        config(['services.routerai.capability_overrides.openai/gpt-4o' => ['streaming' => false]]);
        $this->profile(LLMTaskProfileResolver::EXPERT_FAST, 'custom/text-only', true, 'openai/gpt-4o');
        [, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();
        $content = 'какие вопросы заданы эксперту';
        $streaming = app(ExpertChatStreamingService::class);
        $run = $streaming->start(
            $conversation,
            $content,
            (string) Str::uuid(),
            app(ExpertChatService::class)->requestFingerprint($content, $images, 'fast'),
            $images,
            'fast',
        );
        $events = [];
        $streaming->emit($run, static function (string $event, array $data) use (&$events): void {
            $events[] = compact('event', 'data');
        });

        $this->assertContains('done', array_column($events, 'event'));
        $metadata = $conversation->messages()->where('role', 'assistant')->sole()->metadata;
        $this->assertSame('fast', $metadata['resolved_mode']);
        $this->assertSame('openai/gpt-4o', $metadata['selected_model']);
        $this->assertTrue($metadata['fallback_used']);
        $this->assertSame('capability_mismatch', $metadata['fallback_reason']);
        $this->assertChatModel('openai/gpt-4o');
    }

    private function profile(string $task, string $model, bool $fallbackEnabled = false, ?string $fallbackModel = null): void
    {
        app(LLMSettingsRepository::class)->saveTaskProfile($task, [
            'provider' => 'routerai',
            'model' => $model,
            'enabled' => true,
            'fallback_policy' => 'none',
            'fallback_enabled' => $fallbackEnabled,
            'fallback_provider' => 'routerai',
            'fallback_model' => $fallbackModel,
        ]);
    }

    private function assertCapabilityError(): void
    {
        [$user, $conversation, $images] = $this->conversationWithImages(1);
        $this->fakeAnswer();

        $this->send($user, $conversation, $images, 'fast')->assertStatus(422)
            ->assertJsonPath('code', 'expert_capability_unavailable');
        Http::assertNothingSent();
    }

    /** @return array{User, ExpertConversation, list<string>} */
    private function conversationWithImages(int $count): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $user->id, 'name' => 'Проект', 'domain' => 'other', 'work_type' => 'other']);
        $conversation = $project->conversations()->create(['title' => 'Изображения']);
        $bytes = base64_decode(trim((string) file_get_contents(base_path('tests/Fixtures/Expert/vision-defect.jpg.b64'))), true);
        $this->assertIsString($bytes);
        $ids = [];
        for ($number = 1; $number <= $count; $number++) {
            $path = 'expert/'.$project->public_id.'/materials/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($path, $bytes);
            $ids[] = $project->materials()->create([
                'uploaded_by' => $user->id,
                'original_name' => $number.'.jpg',
                'storage_path' => $path,
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'size' => strlen($bytes),
                'category' => 'image',
                'status' => 'uploaded',
            ])->public_id;
        }

        return [$user, $conversation, $ids];
    }

    private function fakeAnswer(): void
    {
        Http::fake(['*/chat/completions' => Http::response([
            'model' => 'openai/gpt-4o',
            'choices' => [['message' => ['content' => 'Тестовый ответ по изображениям.']]],
        ], 200)]);
    }

    private function send(User $user, ExpertConversation $conversation, array $images, string $mode, string $content = 'какие вопросы заданы эксперту')
    {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/expert/conversations/{$conversation->public_id}/messages",
            ['content' => $content, 'material_public_ids' => $images, 'mode' => $mode],
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        );
    }

    private function assertChatModel(string $model, int $imageCount = 1): void
    {
        $requests = Http::recorded(fn (ClientRequest $request): bool => $request->url() === 'https://routerai.test/api/v1/chat/completions');
        $this->assertCount(1, $requests);
        $request = $requests->first()[0];
        $this->assertSame($model, $request->data()['model']);
        $last = $request->data()['messages'][array_key_last($request->data()['messages'])];
        $this->assertSame($imageCount, count(array_filter($last['content'], static fn (array $part): bool => ($part['type'] ?? '') === 'image_url')));
    }
}
