<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertVisionException;
use App\Services\Expert\ExpertVisionImagePreparer;
use App\Services\LLM\CircuitBreaker;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\LLMErrorClassifier;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\Providers\RouterAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ExpertVisionContextFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::forget('llm:routerai:model_catalog:v1');
    }

    public function test_real_jpeg_and_text_reach_routerai_and_original_stays_unchanged(): void
    {
        [$user, $conversation] = $this->conversation();
        $text = $this->material($conversation->project, 'Описание.txt', 'text/plain', 'Маркер VISION-MIX-9274');
        $fixture = $this->jpegFixture();
        $upload = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$conversation->project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('Дефект.jpg', $fixture)],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $image = ExpertProjectMaterial::query()->where('public_id', $upload->json('public_id'))->firstOrFail();
        $originalHash = hash('sha256', Storage::disk('local')->get($image->storage_path));
        $expected = app(ExpertVisionImagePreparer::class)->prepare($image);
        $this->installRouterAi();

        Http::fake(function (ClientRequest $request) use ($expected) {
            $this->assertSame('https://routerai.test/api/v1/chat/completions', $request->url());
            $this->assertSame(['Bearer test-key'], $request->header('Authorization'));
            $payload = $request->data();
            $this->assertSame('openai/gpt-4o', $payload['model']);
            $this->assertStringContainsString('VISION-MIX-9274', $payload['messages'][1]['content']);
            $this->assertSame(1, substr_count($payload['messages'][1]['content'], 'VISION-MIX-9274'));
            $current = $payload['messages'][array_key_last($payload['messages'])];
            $this->assertSame(['text', 'image_url'], array_column($current['content'], 'type'));
            $this->assertSame('Опиши видимый дефект', $current['content'][0]['text']);
            $url = $current['content'][1]['image_url']['url'];
            $this->assertStringStartsWith('data:image/jpeg;base64,', $url);
            $bytes = base64_decode(substr($url, strlen('data:image/jpeg;base64,')), true);
            $this->assertIsString($bytes);
            $this->assertSame(hash('sha256', $expected->bytes), hash('sha256', $bytes));

            return Http::response([
                'id' => 'chatcmpl-vision-fixture',
                'provider' => 'openai',
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'На изображении обнаружен тестовый объект.']]],
                'usage' => [
                    'prompt_tokens' => 123,
                    'completion_tokens' => 17,
                    'total_tokens' => 140,
                    'prompt_tokens_details' => ['cached_tokens' => 4],
                    'completion_tokens_details' => ['reasoning_tokens' => 3],
                ],
            ]);
        });

        $this->send($user, $conversation, [
            'content' => 'Опиши видимый дефект',
            'material_public_ids' => [$text->public_id, $image->public_id],
        ])->assertCreated()->assertJsonPath('assistant_message.content', 'На изображении обнаружен тестовый объект.')
            ->assertJsonPath('user_message.attachments.0.material_public_id', $text->public_id)
            ->assertJsonPath('user_message.attachments.1.material_public_id', $image->public_id);

        $this->actingAs($user, 'sanctum')->getJson("/api/expert/conversations/{$conversation->public_id}/messages")
            ->assertOk()->assertJsonPath('data.0.attachments.1.kind', 'image')
            ->assertJsonPath('data.0.attachments.1.available', true);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('expert_messages', ['role' => 'assistant', 'content' => 'На изображении обнаружен тестовый объект.']);
        $this->assertSame($originalHash, hash('sha256', Storage::disk('local')->get($image->storage_path)));
    }

    public function test_preparer_supports_formats_orientation_and_fail_closed_validation(): void
    {
        [, $conversation] = $this->conversation();
        $project = $conversation->project;

        foreach ([['jpg', 'image/jpeg'], ['png', 'image/png'], ['webp', 'image/webp']] as [$extension, $mime]) {
            $prepared = app(ExpertVisionImagePreparer::class)->prepare(
                $this->material($project, "Формат.{$extension}", $mime, $this->imageFixture($extension, 80, 40)),
            );
            $this->assertSame(['image/jpeg', 80, 40], [$prepared->mimeType, $prepared->width, $prepared->height]);
            $this->assertStringStartsWith("\xFF\xD8", $prepared->bytes);
        }

        $orientedBytes = $this->withExifOrientation($this->imageFixture('jpg', 60, 40), 6);
        $oriented = $this->material($project, 'Поворот.jpg', 'image/jpeg', $orientedBytes);
        $prepared = app(ExpertVisionImagePreparer::class)->prepare($oriented);
        $this->assertSame([40, 60], [$prepared->width, $prepared->height]);
        $this->assertStringNotContainsString('Exif', $prepared->bytes);
        $this->assertSame($orientedBytes, Storage::disk('local')->get($oriented->storage_path));

        foreach ([
            $this->material($project, 'Подмена.jpg', 'image/jpeg', $this->imageFixture('png', 20, 20)),
            $this->material($project, 'Анимация.gif', 'image/gif', $this->imageFixture('gif', 20, 20)),
            $this->material($project, 'Вектор.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            $this->material($project, 'Повреждено.jpg', 'image/jpeg', "\xFF\xD8broken"),
        ] as $invalid) {
            $this->assertVisionError(fn () => app(ExpertVisionImagePreparer::class)->prepare($invalid), 'vision_material_invalid');
        }

        $valid = $this->material($project, 'Большое.jpg', 'image/jpeg', $this->jpegFixture());
        config(['expert.vision.max_width' => 100]);
        $this->assertVisionError(fn () => app(ExpertVisionImagePreparer::class)->prepare($valid), 'vision_material_too_large');
        config(['expert.vision.max_width' => 10000, 'expert.vision.max_pixels' => 100]);
        $this->assertVisionError(fn () => app(ExpertVisionImagePreparer::class)->prepare($valid), 'vision_material_too_large');
        config(['expert.vision.max_pixels' => 25_000_000, 'expert.vision.max_source_bytes' => 10]);
        $this->assertVisionError(fn () => app(ExpertVisionImagePreparer::class)->prepare($valid), 'vision_material_too_large');
    }

    public function test_limits_and_ownership_fail_before_transport_while_routerai_capability_is_advisory(): void
    {
        [$user, $conversation] = $this->conversation();
        $first = $this->material($conversation->project, 'Первое.jpg', 'image/jpeg', $this->jpegFixture());
        $second = $this->material($conversation->project, 'Второе.png', 'image/png', $this->imageFixture('png', 20, 20));
        [, $foreignProject] = $this->project();
        $foreign = $this->material($foreignProject, 'Чужое.jpg', 'image/jpeg', $this->jpegFixture());
        $this->installRouterAi('deepseek/deepseek-chat');

        config(['expert.vision.max_images_per_message' => 1]);
        $this->send($user, $conversation, ['content' => 'Много', 'material_public_ids' => [$first->public_id, $second->public_id]])
            ->assertUnprocessable()->assertJsonPath('code', 'vision_too_many_images');
        $this->send($user, $conversation, ['content' => 'Чужое', 'material_public_ids' => [$foreign->public_id]])
            ->assertNotFound()->assertJsonPath('code', 'material_context_not_found');

        config(['expert.vision.max_images_per_message' => 4, 'expert.vision.max_total_vision_bytes' => 1]);
        $this->send($user, $conversation, ['content' => 'Payload', 'material_public_ids' => [$first->public_id]])
            ->assertStatus(413)->assertJsonPath('code', 'vision_material_too_large');

        config(['expert.vision.max_total_vision_bytes' => 12 * 1024 * 1024]);
        Http::assertNothingSent();
        Http::fake([
            '*/models' => Http::response(['data' => [[
                'id' => 'deepseek/deepseek-chat',
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            ]]], 200),
            '*/chat/completions' => Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => 'Изображение обработано.']]],
            ], 200),
        ]);
        $messageId = (string) Str::uuid();
        $payload = ['content' => 'Проверь', 'material_public_ids' => [$first->public_id]];
        $firstResponse = $this->send($user, $conversation, $payload, $messageId);
        $this->assertSame(201, $firstResponse->status(), json_encode($firstResponse->json(), JSON_UNESCAPED_UNICODE));
        $firstResponse->assertJsonPath('assistant_message.content', 'Изображение обработано.');
        $this->send($user, $conversation, $payload, $messageId)
            ->assertOk()->assertJsonPath('assistant_message.content', 'Изображение обработано.');
        $this->send($user, $conversation, [
            'content' => 'Проверь',
            'material_public_ids' => [$second->public_id],
        ], $messageId)->assertConflict()->assertJsonPath('code', 'expert_request_conflict');

        Http::assertSentCount(2);
        $this->assertSame(1, $conversation->messages()->where('role', 'user')->count());
        $this->assertSame(1, $conversation->messages()->where('role', 'assistant')->count());
        $this->assertTrue(Storage::disk('local')->exists($first->storage_path));
    }

    public function test_routerai_text_payload_and_usage_regression(): void
    {
        $provider = $this->routerAiProvider();
        Http::fake(function (ClientRequest $request) {
            $this->assertSame([
                ['role' => 'system', 'content' => 'system'],
                ['role' => 'user', 'content' => 'Обычный текст'],
            ], $request->data()['messages']);

            return Http::response([
                'id' => 'chatcmpl-text',
                'provider' => 'openai',
                'service_tier' => 'default',
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['content' => 'Текстовый ответ']]],
                'usage' => [
                    'prompt_tokens' => 12,
                    'completion_tokens' => 5,
                    'total_tokens' => 17,
                    'prompt_tokens_details' => ['cached_tokens' => 2],
                    'completion_tokens_details' => ['reasoning_tokens' => 1],
                ],
            ]);
        });

        $response = $provider->chat(new LLMChatRequest('system', [
            LLMChatMessage::text('user', 'Обычный текст'),
        ]));

        $this->assertSame('Текстовый ответ', $response->content);
        $this->assertSame([12, 5, 17, 2, 1], [
            $response->promptTokens,
            $response->completionTokens,
            $response->totalTokens,
            $response->cachedTokens,
            $response->reasoningTokens,
        ]);
        $this->assertSame('default', $response->metadata['service_tier']);
    }

    public function test_routerai_malformed_response_is_classified(): void
    {
        $provider = $this->routerAiProvider();
        Http::fake(static fn () => Http::response(['choices' => []]));

        $this->assertProviderError(
            fn () => $provider->chat(new LLMChatRequest('system', [LLMChatMessage::text('user', 'test')])),
            'invalid_response',
        );
    }

    public function test_routerai_timeout_is_classified(): void
    {
        $provider = $this->routerAiProvider();
        Http::fake(static fn () => throw new ConnectionException('operation timed out'));

        $this->assertProviderError(
            fn () => $provider->chat(new LLMChatRequest('system', [LLMChatMessage::text('user', 'test')])),
            'timeout',
        );
    }

    public function test_routerai_connection_failure_is_classified(): void
    {
        $provider = $this->routerAiProvider();
        Http::fake(static fn () => throw new ConnectionException('connection refused'));

        $this->assertProviderError(
            fn () => $provider->chat(new LLMChatRequest('system', [LLMChatMessage::text('user', 'test')])),
            'network',
        );
    }

    public function test_routerai_http_retry_and_secret_redaction_regression(): void
    {
        [$user, $conversation] = $this->conversation();
        $this->installRouterAi();
        Http::fakeSequence()
            ->push(['error' => 'temporary'], 429)
            ->push([
                'model' => 'openai/gpt-4o',
                'choices' => [['message' => ['content' => 'Ответ после retry']]],
            ]);

        $this->send($user, $conversation, ['content' => 'Повтори transport'])
            ->assertCreated()
            ->assertJsonPath('assistant_message.content', 'Ответ после retry');
        Http::assertSentCount(2);

        $secret = 'sk-routerai-must-not-leak';
        $logged = [];
        Log::listen(static function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->message, $event->context];
        });
        [$secondUser, $secondConversation] = $this->conversation();
        $this->installRouterAi();
        Http::fake(static fn () => Http::response(['error' => $secret], 500));

        $response = $this->send($secondUser, $secondConversation, ['content' => 'Не раскрывай секрет']);
        $response->assertStatus(503)->assertJsonPath('code', 'expert_chat_unavailable');
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode($logged, JSON_THROW_ON_ERROR));
    }

    private function installRouterAi(string $model = 'openai/gpt-4o'): void
    {
        $provider = $this->routerAiProvider($model);
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

    private function routerAiProvider(string $model = 'openai/gpt-4o'): RouterAiProvider
    {
        return new RouterAiProvider(
            apiKey: 'test-key',
            baseUrl: 'https://routerai.test/api/v1',
            model: $model,
            temperature: 0.2,
            maxTokens: 512,
            timeout: 2,
            connectTimeout: 1,
        );
    }

    private function assertProviderError(callable $action, string $errorType): void
    {
        try {
            $action();
            $this->fail('Expected a provider error.');
        } catch (LLMProviderException $exception) {
            $this->assertSame($errorType, $exception->getErrorType());
        }
    }

    private function send(User $user, ExpertConversation $conversation, array $payload, ?string $messageId = null)
    {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/expert/conversations/{$conversation->public_id}/messages",
            $payload,
            ['X-Expert-Message-Id' => $messageId ?? (string) Str::uuid()],
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
            'category' => $extension === 'txt' ? 'document' : 'image',
            'status' => 'uploaded',
        ]);
    }

    private function jpegFixture(): string
    {
        $encoded = file_get_contents(base_path('tests/Fixtures/Expert/vision-defect.jpg.b64'));
        $this->assertIsString($encoded);
        $decoded = base64_decode(trim($encoded), true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    private function imageFixture(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 238, 242, 246));
        imagefilledrectangle($image, 2, 2, $width - 3, $height - 3, imagecolorallocate($image, 180, 30, 45));
        ob_start();
        $encoded = match ($format) {
            'jpg', 'jpeg' => imagejpeg($image, null, 90),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
            'gif' => imagegif($image),
            default => false,
        };
        $bytes = ob_get_clean();
        imagedestroy($image);
        $this->assertTrue($encoded);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1)
            .pack('n', $orientation)."\0\0".pack('N', 0);
        $payload = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)
            ."\xFF\xE1".pack('n', strlen($payload) + 2).$payload
            .substr($jpeg, 2);
    }

    private function assertVisionError(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected a controlled vision error.');
        } catch (ExpertVisionException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    /** @return array{User, ExpertConversation} */
    private function conversation(): array
    {
        [$user, $project] = $this->project();

        return [$user, $project->conversations()->create(['title' => 'Общий анализ'])];
    }

    /** @return array{User, ExpertProject} */
    private function project(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Проект',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return [$user, $project];
    }
}
