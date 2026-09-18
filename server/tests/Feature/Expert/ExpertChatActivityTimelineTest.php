<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertChatRunRegistry;
use App\Services\Expert\ExpertChatStreamingService;
use App\Services\Expert\ExpertPdfOcrCache;
use App\Services\Expert\ExpertPdfOcrCandidate;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\Contracts\LLMStreamingProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\DTO\LLMParsedFile;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\DTO\LLMStreamEvent;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\Parsing\OpenAiSseStreamParser;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ExpertChatActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['expert.pdf_ocr.enabled' => true]);
    }

    public function test_text_pdf_emits_local_extract_without_any_ocr_activity(): void
    {
        [, $conversation] = $this->conversation();
        $material = $this->material($conversation->project, 'Текстовый.pdf', 'application/pdf', $this->textPdfFixture());
        $provider = new ExpertTimelineFakeProvider(['Ответ по текстовому PDF.']);
        $this->installRouter($provider);

        $events = $this->runStream($conversation, 'Проверь PDF', [$material->public_id]);
        $codes = $this->activityCodes($events);

        $this->assertContains('pdf.local_extract.started', $codes);
        $this->assertContains('pdf.local_extract.completed', $codes);
        $this->assertNotContains('pdf.ocr_cache.hit', $codes);
        $this->assertNotContains('pdf.ocr_cache.miss', $codes);
        $this->assertNotContains('pdf.ocr.started', $codes);
        $this->assertFalse($provider->requests[0]->hasPdfOcrFiles());
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();
        $this->assertSame('Ответ по текстовому PDF.', $assistant->content);
        $this->assertSame('completed', $assistant->metadata['generation_status']);
    }

    public function test_mixed_jpg_and_text_pdf_reach_one_stream_request_with_real_activity(): void
    {
        [, $conversation] = $this->conversation();
        $image = $this->material($conversation->project, 'defect.jpg', 'image/jpeg', $this->jpegFixture());
        $pdf = $this->material($conversation->project, 'document.pdf', 'application/pdf', $this->textPdfFixture());
        $provider = new ExpertTimelineFakeProvider(['Ответ по двум материалам.']);
        $this->installRouter($provider);

        $events = $this->runStream($conversation, 'Сопоставь материалы', [$image->public_id, $pdf->public_id]);
        $codes = $this->activityCodes($events);

        $this->assertContains('material.image_prepare.completed', $codes);
        $this->assertContains('pdf.local_extract.completed', $codes);
        $this->assertNotContains('pdf.ocr.started', $codes);
        $this->assertCount(1, $provider->requests);
        $this->assertTrue($provider->requests[0]->hasImages());
        $this->assertStringContainsString('TEXT-PDF-TIMELINE-48217', json_encode($provider->requests[0]->materialContext, JSON_THROW_ON_ERROR));
        $this->assertSame('done', end($events)['event']);
    }

    public function test_scanned_pdf_stream_persists_ocr_cache_then_reuses_it_without_new_ocr(): void
    {
        [, $conversation] = $this->conversation();
        $bytes = $this->scannedPdfFixture();
        $material = $this->material($conversation->project, 'scan.pdf', 'application/pdf', $bytes);
        $sha = hash('sha256', $bytes);
        $provider = new ExpertTimelineFakeProvider(['OCR-ответ.']);
        $provider->parsedFiles = [new LLMParsedFile($sha, 'scan.pdf', 'OCR-EXPERT-48217', [])];
        $this->installRouter($provider);

        $firstEvents = $this->runStream($conversation, 'Первый запрос', [$material->public_id]);
        $candidate = new ExpertPdfOcrCandidate((string) $conversation->project->public_id, (string) $material->public_id, 'scan.pdf', 'application/pdf', $bytes, $sha, 1);
        $this->assertTrue(Storage::disk('local')->exists(app(ExpertPdfOcrCache::class)->path($candidate)));
        $this->assertContains('pdf.ocr_cache.miss', $this->activityCodes($firstEvents));
        $this->assertContains('pdf.ocr.started', $this->activityCodes($firstEvents));
        $this->assertContains('pdf.ocr.completed', $this->activityCodes($firstEvents));
        $this->assertTrue($provider->requests[0]->hasPdfOcrFiles());

        $secondEvents = $this->runStream($conversation, 'Второй запрос', [$material->public_id]);
        $secondCodes = $this->activityCodes($secondEvents);
        $this->assertContains('pdf.ocr_cache.hit', $secondCodes);
        $this->assertNotContains('pdf.ocr.started', $secondCodes);
        $this->assertFalse($provider->requests[1]->hasPdfOcrFiles());
        $this->assertSame('OCR-EXPERT-48217', $provider->requests[1]->materialContext[0]['text']);
    }

    public function test_image_activity_and_safe_summary_do_not_expose_private_or_raw_reasoning_data(): void
    {
        [, $conversation] = $this->conversation();
        $image = $this->material($conversation->project, 'C:\\private\\photo.jpg', 'image/jpeg', $this->jpegFixture());
        $rawReasoning = 'SECRET-INTERNAL-REASONING-4D2';
        $safeSummary = 'Проверено изображение без раскрытия внутренних рассуждений.';
        $provider = new ExpertTimelineFakeProvider;
        $provider->streamEvents = iterator_to_array((new OpenAiSseStreamParser(true))->parse([
            'data: {"choices":[{"delta":{"reasoning":"'.$rawReasoning.'","reasoning_summary":"'.$safeSummary.'","content":"Ответ."}}]}', "\n\n",
            "data: [DONE]\n\n",
        ], new LLMCancellationToken(static fn (): bool => false)));
        $this->installRouter($provider);
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });

        $events = $this->runStream($conversation, 'Опиши фото', [$image->public_id]);
        $activityPayload = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'activity'));
        $payload = json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $assistant = $conversation->messages()->where('role', 'assistant')->sole();

        $this->assertContains('material.image_prepare.started', $this->activityCodes($events));
        $this->assertContains('material.image_prepare.completed', $this->activityCodes($events));
        $this->assertSame('photo.jpg', collect($activityPayload)->firstWhere('data.code', 'material.image_prepare.started')['data']['detail']);
        $this->assertStringContainsString($safeSummary, $payload);
        $this->assertStringNotContainsString($rawReasoning, $payload);
        $this->assertStringNotContainsString($image->storage_path, $payload);
        $this->assertStringNotContainsString($safeSummary, $assistant->content);
        $this->assertStringNotContainsString($safeSummary, json_encode($assistant->metadata, JSON_THROW_ON_ERROR));
        $loggedPayload = json_encode($logged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($rawReasoning, $loggedPayload);
    }

    public function test_stop_and_interruption_terminalize_active_activities(): void
    {
        [, $conversation] = $this->conversation();
        $provider = new ExpertTimelineFakeProvider(['Первая часть', 'Вторая часть']);
        $this->installRouter($provider);
        $registry = app(ExpertChatRunRegistry::class);
        $runId = null;

        $stopped = $this->runStream($conversation, 'Останови', [], function (string $event, array $payload) use (&$runId, $registry): void {
            if ($event === 'run') {
                $runId = $payload['run_id'];
            }
            if ($event === 'delta' && is_string($runId)) {
                $registry->requestCancellation($runId);
            }
        });
        $this->assertContains('generation.cancelled', $this->activityCodes($stopped));
        $this->assertNoStartedActivitiesAfterTerminal($stopped);

        [, $interruptedConversation] = $this->conversation();
        $interruptedProvider = new ExpertTimelineFakeProvider(['Частичный ответ']);
        $interruptedProvider->failAfterFirstChunk = true;
        $this->installRouter($interruptedProvider);
        $interrupted = $this->runStream($interruptedConversation, 'Прервись', []);
        $this->assertContains('generation.interrupted', $this->activityCodes($interrupted));
        $this->assertNoStartedActivitiesAfterTerminal($interrupted);
    }

    public function test_interrupted_ocr_stream_does_not_write_cache_without_done(): void
    {
        [, $conversation] = $this->conversation();
        $bytes = $this->scannedPdfFixture();
        $material = $this->material($conversation->project, 'broken-scan.pdf', 'application/pdf', $bytes);
        $sha = hash('sha256', $bytes);
        $provider = new ExpertTimelineFakeProvider(['Частичный OCR-ответ.']);
        $provider->failAfterFirstChunk = true;
        $provider->parsedFiles = [new LLMParsedFile($sha, 'broken-scan.pdf', 'Не должен попасть в кеш.', [])];
        $this->installRouter($provider);

        $events = $this->runStream($conversation, 'Прерви OCR', [$material->public_id]);
        $candidate = new ExpertPdfOcrCandidate((string) $conversation->project->public_id, (string) $material->public_id, 'broken-scan.pdf', 'application/pdf', $bytes, $sha, 1);

        $this->assertSame('error', $events[array_key_last($events)]['event']);
        $this->assertFalse(Storage::disk('local')->exists(app(ExpertPdfOcrCache::class)->path($candidate)));
        $this->assertNotContains('pdf.ocr.completed', $this->activityCodes($events));
    }

    public function test_no_streaming_provider_emits_the_legacy_fallback_code_without_duplicate_messages(): void
    {
        [, $conversation] = $this->conversation();
        $this->installRouter(new ExpertTimelineNonStreamingProvider);

        $events = $this->runStream($conversation, 'Используй обычный запрос', []);
        $error = $events[array_key_last($events)];

        $this->assertSame('error', $error['event']);
        $this->assertSame('streaming_not_supported', $error['data']['code']);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(0, $conversation->messages()->where('role', 'assistant')->count());
    }

    /** @return list<array{event: string, data: array<string, mixed>}> */
    private function runStream(ExpertConversation $conversation, string $content, array $materialPublicIds, ?\Closure $afterEmit = null): array
    {
        $service = app(ExpertChatStreamingService::class);
        $run = $service->start(
            $conversation,
            $content,
            (string) Str::uuid(),
            app(\App\Services\Expert\ExpertChatService::class)->requestFingerprint($content, $materialPublicIds),
            $materialPublicIds,
        );
        $events = [];
        $service->emit($run, function (string $event, array $data) use (&$events, $afterEmit): void {
            $events[] = ['event' => $event, 'data' => $data];
            $afterEmit?->__invoke($event, $data);
        });

        return $events;
    }

    /** @param list<array{event: string, data: array<string, mixed>}> $events @return list<string> */
    private function activityCodes(array $events): array
    {
        return array_values(array_map(
            static fn (array $event): string => (string) $event['data']['code'],
            array_filter($events, static fn (array $event): bool => $event['event'] === 'activity'),
        ));
    }

    /** @param list<array{event: string, data: array<string, mixed>}> $events */
    private function assertNoStartedActivitiesAfterTerminal(array $events): void
    {
        $states = [];
        foreach ($events as $event) {
            if ($event['event'] !== 'activity') {
                continue;
            }
            $states[(string) $event['data']['activity_id']] = (string) $event['data']['status'];
        }
        $this->assertNotContains('started', array_values($states));
    }

    private function installRouter(LLMProviderInterface $provider): void
    {
        $settings = Mockery::mock(LLMSettingsRepository::class);
        $settings->shouldReceive('getMode')->andReturn('manual');
        $settings->shouldReceive('getPrimaryProvider')->andReturn('timeline-fake');
        $settings->shouldReceive('getProviderSettings')->with('timeline-fake')->andReturn([]);
        app(CircuitBreaker::class)->reset('timeline-fake');
        $this->app->instance(LLMRouter::class, new LLMRouter(
            app(CircuitBreaker::class),
            $settings,
            app(LLMErrorClassifier::class),
            static fn (): LLMProviderInterface => $provider,
        ));
    }

    private function material(ExpertProject $project, string $name, string $mime, string $contents): ExpertProjectMaterial
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = "expert/{$project->public_id}/materials/".Str::uuid().".{$extension}";
        Storage::disk('local')->put($path, $contents);

        return $project->materials()->create([
            'uploaded_by' => $project->user_id,
            'original_name' => $name,
            'storage_path' => $path,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => strlen($contents),
            'category' => 'document',
            'status' => 'uploaded',
        ]);
    }

    /** @return array{User, ExpertConversation} */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $user->id, 'name' => 'Timeline', 'domain' => 'other', 'work_type' => 'other']);

        return [$user, $project->conversations()->create(['title' => 'Timeline'])];
    }

    private function scannedPdfFixture(): string
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/Expert/scanned-ocr-expert-48217.pdf'));
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function textPdfFixture(): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml('<!doctype html><html><body>TEXT-PDF-TIMELINE-48217</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }

    private function jpegFixture(): string
    {
        $encoded = file_get_contents(base_path('tests/Fixtures/Expert/vision-defect.jpg.b64'));
        $this->assertIsString($encoded);
        $bytes = base64_decode(trim($encoded), true);
        $this->assertIsString($bytes);

        return $bytes;
    }
}

final class ExpertTimelineFakeProvider implements LLMProviderInterface, LLMStreamingProviderInterface
{
    /** @var list<LLMChatRequest> */
    public array $requests = [];

    /** @var list<LLMParsedFile> */
    public array $parsedFiles = [];

    /** @var list<LLMStreamEvent> */
    public array $streamEvents = [];

    public bool $failAfterFirstChunk = false;

    /** @param list<string> $chunks */
    public function __construct(public array $chunks = []) {}

    public function name(): string
    {
        return 'timeline-fake';
    }

    public function model(): string
    {
        return 'timeline-fake-model';
    }

    public function capabilities(): array
    {
        return [LLMCapability::TEXT_INPUT, LLMCapability::IMAGE_INPUT, LLMCapability::PDF_OCR, LLMCapability::STREAMING];
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
        return new LLMResponse('timeline-fake', 'timeline-fake-model', '{"steps":[]}', ['steps' => []], 1);
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        return new LLMChatResponse('timeline-fake', 'timeline-fake-model', 'legacy', 1);
    }

    public function streamChat(LLMChatRequest $request, LLMCancellationToken $token): iterable
    {
        $this->requests[] = $request;
        if ($this->streamEvents !== []) {
            foreach ($this->streamEvents as $event) {
                yield $event;
            }

            return;
        }
        foreach ($this->chunks as $chunk) {
            if ($token->isCancellationRequested()) {
                return;
            }
            yield LLMStreamEvent::delta($chunk);
            if ($this->failAfterFirstChunk) {
                throw LLMProviderException::networkError('timeline-fake', 'offline');
            }
        }
        yield LLMStreamEvent::done([], $this->parsedFiles);
    }
}

final class ExpertTimelineNonStreamingProvider implements LLMProviderInterface
{
    public function name(): string
    {
        return 'timeline-non-streaming';
    }

    public function model(): string
    {
        return 'timeline-non-streaming-model';
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
        return new LLMResponse('timeline-non-streaming', 'timeline-non-streaming-model', '{"steps":[]}', ['steps' => []], 1);
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        return new LLMChatResponse('timeline-non-streaming', 'timeline-non-streaming-model', 'Обычный ответ.', 1);
    }
}
