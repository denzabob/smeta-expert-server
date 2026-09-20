<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\User;
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
use App\Services\LLM\LLMProfileSmokeService;
use App\Services\LLM\LLMSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpertLlmProfileSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_draft_smoke_passes_with_fake_transport_without_creating_expert_data(): void
    {
        $admin = User::factory()->create(['id' => 1]);
        $this->assertSame(1, $admin->id);
        $this->configuredProvider();
        $fake = new SmokeFakeProvider;
        app()->instance(LLMProfileSmokeService::class, new LLMProfileSmokeService(
            app(LLMSettingsRepository::class), static fn () => $fake,
        ));

        foreach (['text', 'streaming', 'vision', 'pdf_ocr'] as $kind) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/admin/llm-profiles/expert-chat/smoke', [
                'provider' => 'routerai', 'model' => 'draft/unsaved', 'kind' => $kind,
            ])->assertOk()->assertJsonPath('status', 'PASS')->assertJsonPath('error_code', null)
                ->assertJsonMissingPath('prompt')->assertJsonMissingPath('api_key');
        }
        $this->assertSame(['text', 'streaming', 'vision', 'pdf_ocr'], $fake->kinds);
        $this->assertDatabaseCount('expert_messages', 0);
        $this->assertDatabaseCount('expert_project_materials', 0);
        $this->assertNull(app(LLMSettingsRepository::class)->getTaskProfile('expert_chat'));
    }

    public function test_each_smoke_failure_returns_only_safe_code_and_leaves_expert_data_empty(): void
    {
        $admin = User::factory()->create(['id' => 1]);
        $this->configuredProvider();
        $fake = new SmokeFakeProvider;
        $fake->fail = true;
        app()->instance(LLMProfileSmokeService::class, new LLMProfileSmokeService(
            app(LLMSettingsRepository::class), static fn () => $fake,
        ));

        foreach (['text', 'streaming', 'vision', 'pdf_ocr'] as $kind) {
            $this->actingAs($admin, 'sanctum')->postJson('/api/admin/llm-profiles/expert-chat/smoke', [
                'provider' => 'routerai', 'model' => 'draft/unsaved', 'kind' => $kind,
            ])->assertOk()->assertJsonPath('status', 'FAIL')->assertJsonPath('error_code', 'provider_auth_failed')
                ->assertDontSee('secret-upstream-body');
        }
        $this->assertDatabaseCount('expert_messages', 0);
        $this->assertDatabaseCount('expert_project_materials', 0);
    }

    private function configuredProvider(): void
    {
        app(LLMSettingsRepository::class)->saveFromAdmin([
            'providers' => ['routerai' => ['api_key' => 'test-router-key', 'model' => 'openai/gpt-4o']],
        ]);
    }
}

final class SmokeFakeProvider implements LLMProviderInterface, LLMStreamingProviderInterface
{
    public bool $fail = false;

    public array $kinds = [];

    public function name(): string
    {
        return 'routerai';
    }

    public function model(): string
    {
        return 'draft/unsaved';
    }

    public function capabilities(): array
    {
        return [LLMCapability::TEXT_INPUT, LLMCapability::STREAMING];
    }

    public function supportsJsonMode(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse
    {
        throw new \LogicException('Not used');
    }

    public function chat(LLMChatRequest $request): LLMChatResponse
    {
        $kind = $request->hasPdfOcrFiles() ? 'pdf_ocr' : ($request->hasImages() ? 'vision' : 'text');
        $this->kinds[] = $kind;
        if ($this->fail) {
            throw new LLMProviderException('secret-upstream-body', 'routerai', 'auth', 401);
        }
        $parsed = $kind === 'pdf_ocr' ? [new LLMParsedFile(
            hash('sha256', file_get_contents(resource_path('fixtures/llm-smoke-scanned.pdf'))),
            'llm-smoke-scanned.pdf', 'OK', [],
        )] : [];

        return new LLMChatResponse('routerai', 'draft/unsaved', 'OK', 1, parsedFiles: $parsed);
    }

    public function streamChat(LLMChatRequest $request, LLMCancellationToken $cancellationToken): iterable
    {
        $this->kinds[] = 'streaming';
        if ($this->fail) {
            throw new LLMProviderException('secret-upstream-body', 'routerai', 'auth', 401);
        }
        yield LLMStreamEvent::delta('OK');
        yield LLMStreamEvent::done();
    }
}
