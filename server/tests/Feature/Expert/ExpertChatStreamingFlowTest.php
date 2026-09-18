<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatRunRegistry;
use App\Services\Expert\ExpertChatStreamingService;
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
        $this->assertFalse($terminal['data']['retryable']);
        $this->assertStringNotContainsString('SECRET-UPSTREAM-BODY', json_encode($events, JSON_THROW_ON_ERROR));
        $this->assertSame('provider_auth_failed', $logged[0]['root_error_code'] ?? null);
        $this->assertTrue($logged[0]['before_first_delta'] ?? false);
        $this->assertSame('4xx', $logged[0]['http_status_class'] ?? null);
        $this->assertStringNotContainsString('PRIVATE-PROMPT', json_encode($logged, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('SECRET-UPSTREAM-BODY', json_encode($logged, JSON_THROW_ON_ERROR));
    }

    public function test_stream_error_distinguishes_timeout_and_missing_model(): void
    {
        foreach ([
            [LLMProviderException::timeout('fake', 1), 'provider_timeout'],
            [LLMProviderException::httpError('fake', 404), 'provider_model_not_found'],
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
            'Продолжи',
            app(\App\Services\Expert\ExpertChatService::class)->requestFingerprint('Продолжи', []),
            new ExpertChatMaterialContext([], []),
        );
        app(ExpertChatStreamingService::class)->emit($run, function (): void {});

        $assistant->refresh();
        $this->assertSame('Первая половина. Вторая половина.', $assistant->content);
        $this->assertSame('completed', $assistant->metadata['generation_status']);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
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
