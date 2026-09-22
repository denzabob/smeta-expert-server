<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatRunRegistry;
use App\Services\Expert\ExpertChatStreamingService;
use App\Services\Expert\ExpertMaterialService;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Contracts\LLMStreamingProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\DTO\LLMStreamEvent;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpertChatStreamingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_stream_persists_one_user_and_one_assistant(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider(['Это ', 'потоковый ', 'ответ.']);
        $this->installRouter($provider);

        $events = $this->runStream($conversation, 'Поток', function () {});

        $protocolEvents = array_values(array_filter($events, static fn (array $event): bool => $event['event'] !== 'activity'));
        $this->assertSame(['run', 'delta', 'delta', 'delta', 'done'], array_column($protocolEvents, 'event'));
        $this->assertSame([1, 2, 3], array_map(
            static fn (array $event): int => $event['data']['seq'],
            array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'delta')),
        ));
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame('Это потоковый ответ.', $assistant->content);
        $this->assertSame('completed', $assistant->metadata['generation_status']);
        $this->assertSame('fake', $assistant->metadata['provider']);
        $this->assertSame('fake-stream', $assistant->metadata['model']);
        $this->assertTrue(Str::isUuid($assistant->metadata['run_id']));
        $this->assertArrayHasKey('latency_ms', $assistant->metadata);
    }

    public function test_failed_pdf_run_releases_lock_and_next_text_message_succeeds(): void
    {
        [$conversation] = $this->conversation();
        Storage::fake('local');
        $path = "expert/{$conversation->project->public_id}/materials/broken.pdf";
        Storage::disk('local')->put($path, 'not a PDF');
        $material = $conversation->project->materials()->create([
            'uploaded_by' => $conversation->project->user_id,
            'original_name' => 'broken.pdf',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 9,
            'category' => 'document',
            'status' => 'uploaded',
        ]);
        $provider = new ExpertStreamingFakeProvider(['OK']);
        $this->installRouter($provider);
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'profile/selected-model', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'Expert chat stream failed.') {
                $logged[] = $event->context;
            }
        });
        $service = app(ExpertChatStreamingService::class);
        $content = 'Проверь PDF';
        $run = $service->start($conversation, $content, (string) Str::uuid(),
            app(\App\Services\Expert\ExpertChatService::class)->requestFingerprint($content, [$material->public_id]),
            [$material->public_id]);
        $events = [];
        $service->emit($run, function (string $event, array $data) use (&$events): void {
            $events[] = compact('event', 'data');
        });
        $this->assertSame('error', end($events)['event']);
        $this->assertSame('pdf_malformed', end($events)['data']['code']);
        $this->assertSame(0, $provider->streamCalls);
        $this->assertSame('profile/selected-model', $logged[0]['effective_model']);
        $this->assertSame('PROFILE', $logged[0]['profile_source']);
        $this->assertNull($logged[0]['actual_upstream_provider']);
        $this->assertNull($logged[0]['actual_upstream_model']);

        $next = $this->runStream($conversation, 'Ответь: OK', function () {});
        $this->assertSame('done', end($next)['event']);
        $this->assertSame('OK', $conversation->messages()->where('role', 'assistant')->sole()->content);
        $this->assertSame(2, $conversation->messages()->where('role', 'user')->count());
    }

    public function test_stop_closes_provider_after_partial_and_persists_stopped_answer(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider(['Первая часть ', 'не должна прийти']);
        $this->installRouter($provider);
        $registry = app(ExpertChatRunRegistry::class);
        $runId = null;
        $events = $this->runStream($conversation, 'Останови', function (string $event, array $data) use (&$runId, $registry): void {
            if ($event === 'run') {
                $runId = $data['run_id'];
            }
            if ($event === 'delta' && is_string($runId)) {
                $registry->requestCancellation($runId);
            }
        });

        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertTrue($provider->transportClosed);
        $this->assertSame('Первая часть ', $assistant->content);
        $this->assertSame('stopped', $assistant->metadata['generation_status']);
        $this->assertSame('cancelled', end($events)['event']);
    }

    public function test_stop_before_first_token_does_not_create_empty_assistant(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider(['не должно прийти']);
        $this->installRouter($provider);
        $registry = app(ExpertChatRunRegistry::class);
        $runId = null;
        $this->runStream($conversation, 'Стоп сразу', function (string $event, array $data) use (&$runId, $registry): void {
            if ($event === 'run') {
                $runId = $data['run_id'];
                $registry->requestCancellation($runId);
            }
        });

        $this->assertTrue($provider->transportClosed);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_failure_after_partial_is_interrupted_without_provider_retry(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider(['Частичный ответ']);
        $provider->failAfterChunks = true;
        $this->installRouter($provider);
        $events = $this->runStream($conversation, 'Ошибка после токена', function () {});

        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame('Частичный ответ', $assistant->content);
        $this->assertSame('interrupted', $assistant->metadata['generation_status']);
        $this->assertSame(1, $provider->streamCalls);
        $this->assertSame('error', end($events)['event']);
    }

    public function test_stream_error_exposes_safe_root_code_and_log_metadata_without_provider_body(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider([]);
        $provider->failure = LLMProviderException::httpError('fake', 401, 'SECRET-UPSTREAM-BODY');
        $this->installRouter($provider);
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'profile/selected-model', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            if ($event->message === 'Expert chat stream failed.') {
                $logged[] = $event->context;
            }
        });
        $events = $this->runStream($conversation, 'PRIVATE-PROMPT', function () {});

        $terminal = end($events);
        $this->assertSame('error', $terminal['event']);
        $this->assertSame('provider_auth_failed', $terminal['data']['code']);
        $this->assertSame('provider_auth_failed', $terminal['data']['error_code']);
        $this->assertIsString($terminal['data']['run_id']);
        $this->assertFalse($terminal['data']['retryable']);
        $this->assertSame('model.request.started', $terminal['data']['last_activity_code']);
        $this->assertStringNotContainsString('SECRET-UPSTREAM-BODY', json_encode($events, JSON_THROW_ON_ERROR));
        $this->assertSame('provider_auth_failed', $logged[0]['root_error_code'] ?? null);
        $this->assertFalse($logged[0]['retryable'] ?? true);
        $this->assertTrue($logged[0]['before_first_delta'] ?? false);
        $this->assertSame('4xx', $logged[0]['http_status_class'] ?? null);
        $this->assertSame('expert_fast', $logged[0]['task_profile'] ?? null);
        $this->assertSame('routerai', $logged[0]['effective_provider'] ?? null);
        $this->assertSame('profile/selected-model', $logged[0]['effective_model'] ?? null);
        $this->assertSame('PROFILE', $logged[0]['profile_source'] ?? null);
        $this->assertNull($logged[0]['actual_upstream_model'] ?? null);
        $this->assertStringNotContainsString('PRIVATE-PROMPT', json_encode($logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SECRET-UPSTREAM-BODY', json_encode($logged, JSON_THROW_ON_ERROR));
    }

    public function test_stream_error_preserves_safe_root_codes(): void
    {
        foreach ([
            [LLMProviderException::timeout('fake', 1), 'provider_timeout'],
            [LLMProviderException::httpError('fake', 401), 'provider_auth_failed'],
            [LLMProviderException::httpError('fake', 404), 'provider_model_not_found'],
            [LLMProviderException::httpError('fake', 422), 'provider_validation_failed'],
            [LLMProviderException::httpError('fake', 429), 'provider_rate_limited'],
            [LLMProviderException::httpError('fake', 503), 'provider_server_error'],
            [new LLMProviderException('Unexpected response type', 'fake', 'unexpected_content_type'), 'provider_unexpected_content_type'],
            [new LLMProviderException('Malformed stream', 'fake', 'stream_malformed'), 'stream_malformed'],
            [new LLMProviderException('EOF before done', 'fake', 'stream_eof_without_terminal'), 'stream_eof_without_terminal'],
        ] as [$failure, $expectedCode]) {
            [$conversation] = $this->conversation();
            $provider = new ExpertStreamingFakeProvider([]);
            $provider->failure = $failure;
            $this->installRouter($provider);
            $events = $this->runStream($conversation, 'Проверка', function () {});
            $terminal = end($events);
            $this->assertSame('error', $terminal['event']);
            $this->assertSame($expectedCode, $terminal['data']['code']);
        }
    }

    public function test_retry_before_first_token_and_continue_reuses_same_assistant(): void
    {
        [$conversation] = $this->conversation();
        $provider = new ExpertStreamingFakeProvider(['Первая половина.']);
        $provider->failBeforeFirstOnce = true;
        $this->installRouter($provider);
        $registry = app(ExpertChatRunRegistry::class);
        $runId = null;
        $this->runStream($conversation, 'Продолжи', function (string $event, array $data) use (&$runId, $registry): void {
            if ($event === 'run') {
                $runId = $data['run_id'];
            }
            if ($event === 'delta' && is_string($runId)) {
                $registry->requestCancellation($runId);
            }
        });
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame(2, $provider->streamCalls);

        $provider->chunks = [' Вторая половина.'];
        $run = app(ExpertChatStreamingService::class)->continueRun(
            $conversation,
            $assistant,
        );
        app(ExpertChatStreamingService::class)->emit($run, function (): void {});

        $assistant->refresh();
        $this->assertSame('Первая половина. Вторая половина.', $assistant->content);
        $this->assertSame('completed', $assistant->metadata['generation_status']);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
    }

    public function test_continue_after_reload_uses_original_persisted_material_without_frontend_snapshot(): void
    {
        [$conversation] = $this->conversation();
        Storage::fake('local');
        $path = "expert/{$conversation->project->public_id}/materials/original.txt";
        Storage::disk('local')->put($path, 'Исходный материал для продолжения');
        $material = $conversation->project->materials()->create([
            'uploaded_by' => $conversation->project->user_id,
            'original_name' => 'original.txt',
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen('Исходный материал для продолжения'),
            'category' => 'document',
            'status' => 'uploaded',
        ]);
        $provider = new ExpertStreamingFakeProvider(['Первая часть.']);
        $this->installRouter($provider);
        $service = app(ExpertChatStreamingService::class);
        $content = 'Продолжи анализ файла';
        $run = $service->start($conversation, $content, (string) Str::uuid(),
            app(\App\Services\Expert\ExpertChatService::class)->requestFingerprint($content, [$material->public_id]),
            [$material->public_id]);
        $registry = app(ExpertChatRunRegistry::class);
        $runId = null;
        $service->emit($run, function (string $event, array $data) use ($registry, &$runId): void {
            if ($event === 'run') {
                $runId = $data['run_id'];
            }
            if ($event === 'delta') {
                $registry->requestCancellation($runId);
            }
        });
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame('stopped', $assistant->metadata['generation_status']);

        $provider->chunks = [' Вторая часть.'];
        $response = $this->actingAs($conversation->project->user, 'sanctum')->postJson(
            "/api/expert/conversations/{$conversation->public_id}/messages/{$assistant->public_id}/continue/stream",
            [],
        );
        $response->assertOk();
        $this->assertStringContainsString('event: done', $response->streamedContent());

        $this->assertSame('Первая часть. Вторая часть.', $assistant->refresh()->content);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertSame([$material->public_id], array_column($provider->chatRequests[1]->materialContext, 'public_id'));
    }

    public function test_continue_reports_deleted_original_material_without_substituting_a_file(): void
    {
        [$conversation] = $this->conversation();
        Storage::fake('local');
        $path = "expert/{$conversation->project->public_id}/materials/original.txt";
        Storage::disk('local')->put($path, 'Исходный файл');
        $material = $conversation->project->materials()->create([
            'uploaded_by' => $conversation->project->user_id,
            'original_name' => 'original.txt',
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen('Исходный файл'),
            'category' => 'document',
            'status' => 'uploaded',
        ]);
        $chat = app(\App\Services\Expert\ExpertChatService::class);
        $content = 'Исходный вопрос';
        $user = $chat->prepareStreamingUserMessage($conversation, $content, (string) Str::uuid(),
            $chat->requestFingerprint($content, [$material->public_id]), [$material->public_id]);
        $assistant = $chat->persistStreamingAssistant($conversation, $user, 'Частичный ответ', 'stopped', (string) Str::uuid(), 'cancelled');
        $this->assertNotNull($assistant);
        app(ExpertMaterialService::class)->delete($material);

        $this->actingAs($conversation->project->user, 'sanctum')->postJson(
            "/api/expert/conversations/{$conversation->public_id}/messages/{$assistant->public_id}/continue/stream",
            [],
        )->assertStatus(422)->assertJsonPath('code', 'original_material_unavailable');
        $this->assertSame(2, $conversation->messages()->count());
    }

    /** @return list<array{event:string,data:array<string,mixed>}> */
    private function runStream(ExpertConversation $conversation, string $content, \Closure $afterEmit): array
    {
        $service = app(ExpertChatStreamingService::class);
        $run = $service->start($conversation, $content, (string) Str::uuid(), app(\App\Services\Expert\ExpertChatService::class)->requestFingerprint($content, []), new ExpertChatMaterialContext([], []));
        $events = [];
        $service->emit($run, function (string $event, array $data) use (&$events, $afterEmit): void {
            $events[] = ['event' => $event, 'data' => $data];
            $afterEmit($event, $data);
        });

        return $events;
    }

    private function installRouter(ExpertStreamingFakeProvider $provider): void
    {
        $this->app->instance(LLMRouter::class, new LLMRouter(app(CircuitBreaker::class), app(LLMSettingsRepository::class), app(LLMErrorClassifier::class), static fn (): LLMProviderInterface => $provider));
    }

    /** @return array{ExpertConversation} */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $user->id, 'name' => 'Поток', 'domain' => 'other', 'work_type' => 'other']);

        return [$project->conversations()->create(['title' => 'Поток'])];
    }
}

final class ExpertStreamingFakeProvider implements LLMProviderInterface, LLMStreamingProviderInterface
{
    /** @var list<LLMChatRequest> */
    public array $chatRequests = [];

    public int $streamCalls = 0;

    public bool $transportClosed = false;

    public bool $failAfterChunks = false;

    public bool $failBeforeFirstOnce = false;

    public ?LLMProviderException $failure = null;

    /** @param list<string> $chunks */
    public function __construct(public array $chunks) {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-stream';
    }

    public function capabilities(): array
    {
        return [LLMCapability::TEXT_INPUT, LLMCapability::STREAMING];
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
        return new LLMResponse('fake', 'fake', '{"steps":[]}', ['steps' => []], 1);
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        return new LLMChatResponse('fake', 'fake', 'legacy', 1);
    }

    public function streamChat(LLMChatRequest $request, LLMCancellationToken $token): iterable
    {
        $this->streamCalls++;
        $this->chatRequests[] = $request;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->failBeforeFirstOnce) {
            $this->failBeforeFirstOnce = false;
            throw LLMProviderException::networkError('fake', 'offline');
        }
        foreach ($this->chunks as $chunk) {
            if ($token->isCancellationRequested()) {
                $this->transportClosed = true;

                return;
            }
            yield LLMStreamEvent::delta($chunk);
            if ($this->failAfterChunks) {
                throw LLMProviderException::networkError('fake', 'offline');
            }
        }
        yield LLMStreamEvent::done();
    }
}
