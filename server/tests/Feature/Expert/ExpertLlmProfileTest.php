<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\User;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMImageContent;
use App\Services\LLM\DTO\LLMTextContent;
use App\Services\LLM\Exceptions\LLMUnsupportedCapabilityException;
use App\Services\LLM\LLMEffectiveCapabilityResolver;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;
use App\Services\LLM\RouterAiModelCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExpertLlmProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('llm:routerai:model_catalog:v1');
        app(LLMSettingsRepository::class)->saveFromAdmin([
            'mode' => 'manual',
            'primary_provider' => 'routerai',
            'providers' => ['routerai' => ['api_key' => 'test-router-key', 'model' => 'openai/gpt-4o', 'base_url' => 'https://routerai.ru/api/v1']],
        ]);
    }

    public function test_absent_profile_uses_global_model_and_profile_change_affects_next_request(): void
    {
        $this->assertNull(app(LLMTaskProfileResolver::class)->active('expert_chat'));
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200)]);
        $router = app(LLMRouter::class);
        $request = new LLMChatRequest('Test', [LLMChatMessage::text('user', 'Ответь: OK')]);
        $router->chat($request, taskProfile: 'expert_chat');
        $this->assertSame('openai/gpt-4o', Http::recorded()[0][0]['model']);

        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'openai/gpt-4.1', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $router->chat($request, taskProfile: 'expert_chat');
        $this->assertSame('openai/gpt-4.1', Http::recorded()[1][0]['model']);
        $this->assertSame('openai/gpt-4o', app(LLMSettingsRepository::class)->getProviderSettings('routerai')['model']);

        app(LLMSettingsRepository::class)->saveFromAdmin([
            'providers' => ['deepseek' => ['api_key' => 'test-deepseek-key', 'model' => 'deepseek-chat']],
        ]);
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'deepseek', 'model' => 'deepseek-reasoner', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $router->chat($request, taskProfile: 'expert_chat');
        $this->assertSame('deepseek-reasoner', Http::recorded()[2][0]['model']);
        $this->assertSame('routerai', $this->app->make(LLMSettingsRepository::class)->getPrimaryProvider());
    }

    public function test_catalog_caches_and_stale_refresh_preserves_pinned_profile(): void
    {
        $models = [
            ['id' => 'custom/pinned', 'name' => 'Pinned', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']]],
        ];
        $catalogAvailable = true;
        Http::fake(static function () use (&$catalogAvailable, $models) {
            return $catalogAvailable ? Http::response(['data' => $models], 200) : Http::response([], 503);
        });
        $catalog = app(RouterAiModelCatalogService::class);
        $this->assertSame('fresh', $catalog->snapshot()['status']);
        $this->assertSame('fresh', $catalog->snapshot()['status']);
        Http::assertSentCount(1);

        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'legacy/unknown', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $catalogAvailable = false;
        $this->assertSame('stale', $catalog->snapshot(true)['status']);
        $this->assertSame('custom/pinned', $catalog->cachedModel('custom/pinned')['id']);
        $this->assertSame('legacy/unknown', app(LLMSettingsRepository::class)->getTaskProfile('expert_chat')['model']);
    }

    public function test_dynamic_capabilities_override_legacy_and_ocr_is_gateway_level(): void
    {
        $this->catalogFake([[
            'id' => 'openai/gpt-4o', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            'supported_parameters' => ['tools'], 'streaming' => false,
        ]]);
        app(RouterAiModelCatalogService::class)->snapshot();
        $capabilities = app(LLMEffectiveCapabilityResolver::class)->resolve('routerai', 'openai/gpt-4o');
        $this->assertTrue($capabilities['text_input']);
        $this->assertFalse($capabilities['image_input']);
        $this->assertFalse($capabilities['streaming']);
        $this->assertTrue($capabilities['tools']);
        $this->assertFalse($capabilities['structured_output']);
        $this->assertTrue($capabilities['pdf_ocr']);
    }

    public function test_profile_fallback_policy_uses_existing_global_chain_only_when_enabled(): void
    {
        $settings = app(LLMSettingsRepository::class);
        $settings->saveFromAdmin(['mode' => 'auto', 'fallback_providers' => ['deepseek']]);
        $settings->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/pinned', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $resolver = app(LLMTaskProfileResolver::class);
        $this->assertSame(['routerai'], $resolver->executionPlan('expert_chat'));
        $settings->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/pinned', 'enabled' => true, 'fallback_policy' => 'global',
        ]);
        $this->assertSame(['routerai', 'deepseek'], $resolver->executionPlan('expert_chat'));
        $settings->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/pinned', 'enabled' => false, 'fallback_policy' => 'global',
        ]);
        $this->assertNull($resolver->active('expert_chat'));
        $this->assertSame(['routerai', 'deepseek'], app(LLMRouter::class)->buildExecutionPlan('expert_chat'));
    }

    public function test_profile_rejects_unsupported_image_and_uses_synchronous_completion_when_streaming_is_unsupported(): void
    {
        $this->catalogFake([[
            'id' => 'custom/text-only', 'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            'streaming' => false,
        ]]);
        app(RouterAiModelCatalogService::class)->snapshot();
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/text-only', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $router = app(LLMRouter::class);
        $image = new LLMChatRequest('Test', [new LLMChatMessage('user', [
            new LLMTextContent('Test'), new LLMImageContent('fixture', 'red.png', 'image/png', 'bytes', 1, 1),
        ])]);
        try {
            $router->chat($image, taskProfile: 'expert_chat');
            $this->fail('Image input should be rejected before transport.');
        } catch (LLMUnsupportedCapabilityException $exception) {
            $this->assertSame('image_input', $exception->capability->value);
        }

        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200)]);
        $events = iterator_to_array($router->streamChat(
            new LLMChatRequest('Test', [LLMChatMessage::text('user', 'Ответь: OK')]),
            new LLMCancellationToken(static fn (): bool => false),
            taskProfile: 'expert_chat',
        ));
        $this->assertSame(['delta', 'done'], array_map(static fn ($event) => $event->type, $events));
        $this->assertTrue($events[1]->metadata['sync_fallback']);
        $this->assertSame('custom/text-only', Http::recorded()[0][0]['model']);
    }

    public function test_admin_can_save_unknown_model_with_audit_but_non_admin_cannot_read_or_test(): void
    {
        $admin = User::factory()->create(['id' => 1]);
        $this->assertSame(1, $admin->id);
        $other = User::factory()->create();
        $url = '/api/admin/llm-profiles/expert-chat';
        $this->actingAs($other, 'sanctum')->getJson($url)->assertForbidden();
        $this->postJson($url.'/smoke', ['provider' => 'routerai', 'model' => 'x/y', 'kind' => 'text'])->assertForbidden();
        $this->getJson('/api/admin/llm-model-catalog/routerai')->assertForbidden();
        $this->postJson('/api/admin/llm-model-catalog/routerai/refresh')->assertForbidden();
        $this->getJson($url.'/preview?provider=routerai&model=x%2Fy')->assertForbidden();

        $this->actingAs($admin, 'sanctum')->putJson($url, [
            'provider' => 'routerai', 'model' => 'legacy/unknown', 'enabled' => true, 'fallback_policy' => 'none',
        ])->assertOk()->assertJsonPath('effective.model', 'legacy/unknown')->assertJsonPath('source', 'PROFILE')
            ->assertDontSee('test-router-key');
        $this->getJson($url)->assertOk()->assertJsonPath('model_in_catalog', false)->assertDontSee('test-router-key');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'llm_profile_update', 'admin_user_id' => $admin->id]);
    }

    public function test_catalog_search_and_compatible_filter_are_server_side_and_bounded(): void
    {
        $admin = User::factory()->create(['id' => 1]);
        $models = [];
        for ($index = 0; $index < 45; $index++) {
            $models[] = [
                'id' => 'vendor/model-'.$index,
                'name' => 'Searchable '.$index,
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            ];
        }
        $models[] = [
            'id' => 'vendor/image-only',
            'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['image']],
        ];
        $models[0]['pricing'] = ['prompt' => '0.000005', 'completion' => '0.000020'];
        $models[0]['pricing_units'] = ['prompt' => 'token', 'completion' => 'token'];
        $models[1]['pricing'] = ['prompt' => '0.000001', 'completion' => '0.000002'];
        $models[1]['pricing_units'] = ['prompt' => 'token', 'completion' => 'token'];
        $this->catalogFake($models);
        $base = '/api/admin/llm-model-catalog/routerai';
        $this->actingAs($admin, 'sanctum')->getJson($base.'?filter[]=compatible')->assertOk()
            ->assertJsonPath('total', 45)->assertJsonCount(40, 'models');
        $this->getJson($base.'?filter[]=compatible&page=2')->assertOk()->assertJsonCount(5, 'models');
        $this->getJson($base.'?q=model-42&filter[]=compatible')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('models.0.id', 'vendor/model-42');
        $this->getJson($base.'?filter[]=compatible&sort=typical_cost')->assertOk()
            ->assertJsonPath('models.0.id', 'vendor/model-1')
            ->assertJsonPath('models.1.id', 'vendor/model-0');
        Http::assertSentCount(1);
    }

    private function catalogFake(array $models): void
    {
        Http::fake(['*/models' => Http::response(['data' => $models], 200)]);
    }
}
