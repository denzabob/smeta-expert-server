<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use Dompdf\Dompdf;
use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertChatMaterialContextBuilder;
use App\Services\Expert\ExpertMaterialContextException;
use App\Services\Expert\ExpertMaterialTextExtractor;
use App\Services\Expert\ExpertPdfOcrCache;
use App\Services\Expert\ExpertPdfOcrCandidate;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMParsedFile;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\Providers\RouterAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class ExpertPdfOcrAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'expert.pdf_ocr.enabled' => true,
            'expert.pdf_ocr.engine' => 'mistral-ocr',
        ]);
    }

    public function test_acceptance_a_and_b_scanned_pdf_is_cached_and_then_sent_as_ocr_text(): void
    {
        [$user, $conversation] = $this->conversation();
        $fixture = $this->scannedPdfFixture();
        $fixtureSha = hash('sha256', $fixture);
        $material = $this->uploadPdf($user, $conversation->project, $fixture);
        $disk = Storage::disk('local');

        $this->assertTrue($disk->exists($material->storage_path));
        $this->assertSame('application/pdf', $material->mime_type);
        $this->assertNoUsableLocalPdfText($material, $fixture);

        $this->installRouterAi();
        $requests = [];
        Http::fake(function (ClientRequest $request) use (&$requests, $fixtureSha) {
            $requests[] = $request;
            $payload = $request->data();

            if (count($requests) === 1) {
                $current = $payload['messages'][array_key_last($payload['messages'])];
                $this->assertSame(['text', 'file'], array_column($current['content'], 'type'));
                $fileData = $current['content'][1]['file']['file_data'];
                $prefix = 'data:application/pdf;base64,';
                $this->assertStringStartsWith($prefix, $fileData);
                $decoded = base64_decode(substr($fileData, strlen($prefix)), true);
                $this->assertIsString($decoded);
                $this->assertSame($fixtureSha, hash('sha256', $decoded));
                $this->assertSame([
                    ['id' => 'file-parser', 'pdf' => ['engine' => 'mistral-ocr']],
                ], $payload['plugins']);
            }

            return Http::response($this->routerResponse($fixtureSha), 200);
        });

        $this->send($user, $conversation, ['content' => 'Первый вопрос', 'material_public_ids' => [$material->public_id]])
            ->assertCreated()
            ->assertJsonPath('assistant_message.content', 'OCR-ответ для OCR-EXPERT-48217.');

        $candidate = $this->candidate($material, $fixture);
        $cache = app(ExpertPdfOcrCache::class);
        $cachePath = $cache->path($candidate);
        $this->assertTrue($disk->exists($cachePath));
        $cached = json_decode($disk->get($cachePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($fixtureSha, $cached['source_sha256']);
        $this->assertSame('OCR-EXPERT-48217', trim($cached['text']));
        $this->assertDatabaseHas('expert_messages', [
            'role' => 'assistant',
            'content' => 'OCR-ответ для OCR-EXPERT-48217.',
        ]);

        $this->send($user, $conversation, ['content' => 'Второй вопрос', 'material_public_ids' => [$material->public_id]])
            ->assertCreated()
            ->assertJsonPath('assistant_message.content', 'OCR-ответ для OCR-EXPERT-48217.');

        $this->assertCount(2, $requests);
        $secondPayload = $requests[1]->data();
        $secondJson = json_encode($secondPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('OCR-EXPERT-48217', $secondJson);
        $this->assertStringNotContainsString('file_data', $secondJson);
        $this->assertArrayNotHasKey('plugins', $secondPayload);
    }

    public function test_ocr_cache_is_project_scoped_changed_sha_misses_and_material_delete_invalidates_it(): void
    {
        $fixture = $this->scannedPdfFixture();
        $sha = hash('sha256', $fixture);
        $cache = app(ExpertPdfOcrCache::class);
        $sameMaterialId = (string) Str::uuid();
        $projectA = (string) Str::uuid();
        $projectB = (string) Str::uuid();
        $candidateA = new ExpertPdfOcrCandidate($projectA, $sameMaterialId, 'scan.pdf', 'application/pdf', $fixture, $sha, 1);
        $candidateB = new ExpertPdfOcrCandidate($projectB, $sameMaterialId, 'scan.pdf', 'application/pdf', $fixture, $sha, 1);

        $cache->put($candidateA, new LLMParsedFile($sha, 'scan.pdf', 'OCR-A', []));
        $cache->put($candidateB, new LLMParsedFile($sha, 'scan.pdf', 'OCR-B', []));
        $this->assertNotSame($cache->path($candidateA), $cache->path($candidateB));
        $this->assertSame('OCR-A', $cache->get($candidateA)?->text);
        $this->assertSame('OCR-B', $cache->get($candidateB)?->text);

        [$user, $conversation] = $this->conversation();
        $material = $this->uploadPdf($user, $conversation->project, $fixture);
        $candidate = $this->candidate($material, $fixture);
        $cache->put($candidate, new LLMParsedFile($sha, $material->original_name, 'OCR-EXPERT-48217', []));
        $this->assertTrue(Storage::disk('local')->exists($cache->path($candidate)));

        $changed = $fixture . "\n% changed source fingerprint\n";
        Storage::disk('local')->put($material->storage_path, $changed);
        $material->forceFill(['size' => strlen($changed)])->save();

        $context = app(ExpertChatMaterialContextBuilder::class)->build($conversation->project, [$material->public_id]);
        $this->assertCount(1, $context->files);
        $this->assertSame(hash('sha256', $changed), $context->files[0]->sha256);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/expert/materials/{$material->public_id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('expert_project_materials', ['public_id' => $material->public_id]);
        $this->assertFalse(Storage::disk('local')->exists($cache->path($candidate)));
        $this->assertSame([], Storage::disk('local')->allFiles("expert/{$conversation->project->public_id}/ocr/{$material->public_id}"));
    }

    public function test_wrong_hash_malformed_oversized_and_external_annotations_fail_closed_without_fetching_urls(): void
    {
        [$user, $conversation] = $this->conversation();
        $fixture = $this->scannedPdfFixture();
        $sha = hash('sha256', $fixture);
        $material = $this->uploadPdf($user, $conversation->project, $fixture);
        $calls = 0;

        $this->installRouterAi();
        Http::fake(function (ClientRequest $request) use (&$calls, $sha) {
            $calls++;
            $annotation = match ($calls) {
                1 => $this->fileAnnotation(str_repeat('0', 64), [['type' => 'text', 'text' => 'OCR-EXPERT-48217']]),
                2 => $this->fileAnnotation($sha, [
                    ['type' => 'text', 'text' => 'OCR-EXPERT-48217'],
                    ['type' => 'unexpected'],
                ]),
                3 => $this->fileAnnotation($sha, [['type' => 'text', 'text' => 'OCR-EXPERT-48217']]),
                default => $this->fileAnnotation($sha, [
                    ['type' => 'text', 'text' => 'OCR'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://evil.example/ocr.png']],
                ]),
            };

            if ($calls === 3) {
                config(['expert.pdf_ocr.max_annotation_text_chars' => 8]);
            }

            return Http::response($this->routerResponseWithAnnotation($annotation), 200);
        });

        foreach (['wrong-hash', 'malformed', 'oversized', 'external-url'] as $question) {
            $this->send($user, $conversation, [
                'content' => $question,
                'material_public_ids' => [$material->public_id],
            ])->assertStatus(502)->assertJsonPath('code', 'pdf_ocr_failed');
        }

        Http::assertSentCount(4);
        Http::assertNotSent(static fn (ClientRequest $request): bool => $request->url() === 'https://evil.example/ocr.png');
        $this->assertSame([], Storage::disk('local')->allFiles("expert/{$conversation->project->public_id}/ocr"));
    }

    public function test_text_pdf_never_starts_ocr_and_malformed_encrypted_or_oversized_pdfs_stop_before_transport(): void
    {
        [$user, $conversation] = $this->conversation();
        $textPdf = $this->material($conversation->project, 'text.pdf', 'application/pdf', $this->textPdfFixture());
        $textContext = app(ExpertChatMaterialContextBuilder::class)->build($conversation->project, [$textPdf->public_id]);
        $this->assertCount(1, $textContext->textMaterials);
        $this->assertSame([], $textContext->files);
        $this->assertSame([], $textContext->ocrCandidates);
        $this->assertStringContainsString('TEXT-PDF-48217-EXTRACTABLE', $textContext->textMaterials[0]['text']);

        $malformed = $this->material($conversation->project, 'malformed.pdf', 'application/pdf', 'not a pdf');
        $encrypted = $this->material($conversation->project, 'encrypted.pdf', 'application/pdf', "%PDF-1.4\n/Encrypt 9 0 R\n");
        $oversized = $this->material($conversation->project, 'oversized.pdf', 'application/pdf', $this->scannedPdfFixture());
        config(['expert.pdf_ocr.max_source_bytes' => strlen($this->scannedPdfFixture()) - 1]);

        Http::fake();
        $this->send($user, $conversation, ['content' => 'malformed', 'material_public_ids' => [$malformed->public_id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'material_context_extraction_failed');
        $this->send($user, $conversation, ['content' => 'encrypted', 'material_public_ids' => [$encrypted->public_id]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'material_context_extraction_failed');
        $this->send($user, $conversation, ['content' => 'oversized', 'material_public_ids' => [$oversized->public_id]])
            ->assertStatus(413)
            ->assertJsonPath('code', 'pdf_ocr_too_large');
        Http::assertNothingSent();
    }

    private function installRouterAi(): void
    {
        config([
            'services.routerai.model_capabilities' => [
                'openai/gpt-4o*' => ['image_input', 'pdf_ocr'],
            ],
        ]);
        $provider = new RouterAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://routerai.test/api/v1',
            model: 'openai/gpt-4o',
            temperature: 0.2,
            maxTokens: 512,
            timeout: 2,
            connectTimeout: 1,
        );
        $settings = Mockery::mock(LLMSettingsRepository::class);
        $settings->shouldReceive('getMode')->andReturn('manual');
        $settings->shouldReceive('getPrimaryProvider')->andReturn('routerai');
        $settings->shouldReceive('getProviderSettings')->with('routerai')->andReturn([]);
        app(CircuitBreaker::class)->reset('routerai');
        $this->app->instance(LLMRouter::class, new LLMRouter(
            app(CircuitBreaker::class),
            $settings,
            app(LLMErrorClassifier::class),
            static fn (string $name, array $providerSettings): LLMProviderInterface => $provider,
        ));
    }

    private function routerResponse(string $sha, string $text = 'OCR-EXPERT-48217'): array
    {
        return $this->routerResponseWithAnnotation($this->fileAnnotation($sha, [['type' => 'text', 'text' => $text]]));
    }

    private function routerResponseWithAnnotation(array $annotation): array
    {
        return [
            'id' => 'chatcmpl-pdf-ocr',
            'provider' => 'openai',
            'model' => 'openai/gpt-4o',
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => 'OCR-ответ для OCR-EXPERT-48217.',
                    'annotations' => [$annotation],
                ],
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8, 'total_tokens' => 28],
        ];
    }

    private function fileAnnotation(string $sha, array $content): array
    {
        return [
            'type' => 'file',
            'file' => [
                'hash' => $sha,
                'name' => 'scanned-ocr-expert-48217.pdf',
                'content' => $content,
            ],
        ];
    }

    private function uploadPdf(User $user, ExpertProject $project, string $bytes): ExpertProjectMaterial
    {
        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('scanned-ocr-expert-48217.pdf', $bytes)],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return ExpertProjectMaterial::query()->where('public_id', $response->json('public_id'))->firstOrFail();
    }

    private function assertNoUsableLocalPdfText(ExpertProjectMaterial $material, string $bytes): void
    {
        try {
            app(ExpertMaterialTextExtractor::class)->extract(
                $material,
                $bytes,
                Storage::disk('local')->path($material->storage_path),
            );
            $this->fail('The scanned fixture unexpectedly exposed a usable local PDF text layer.');
        } catch (ExpertMaterialContextException $exception) {
            $this->assertSame('material_context_extraction_failed', $exception->errorCode);
            $this->assertSame('pdf_no_usable_text:1', $exception->reason);
        }
    }

    private function candidate(ExpertProjectMaterial $material, string $bytes): ExpertPdfOcrCandidate
    {
        return new ExpertPdfOcrCandidate(
            (string) $material->project->public_id,
            (string) $material->public_id,
            (string) $material->original_name,
            'application/pdf',
            $bytes,
            hash('sha256', $bytes),
            1,
        );
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

    private function scannedPdfFixture(): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/Expert/scanned-ocr-expert-48217.pdf'));
        $this->assertIsString($contents);

        return $contents;
    }

    private function textPdfFixture(): string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml('<!doctype html><html><body>TEXT-PDF-48217-EXTRACTABLE</body></html>');
        $dompdf->render();

        return $dompdf->output();
    }

    private function send(User $user, ExpertConversation $conversation, array $payload)
    {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/expert/conversations/{$conversation->public_id}/messages",
            $payload,
            ['X-Expert-Message-Id' => (string) Str::uuid()],
        );
    }

    /** @return array{User, ExpertConversation} */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'OCR acceptance',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return [$user, $project->conversations()->create(['title' => 'OCR acceptance'])];
    }
}
