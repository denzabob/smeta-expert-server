<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\User;
use App\Services\LLM\DTO\LLMCancellationToken;
use App\Services\LLM\DTO\LLMChatMessage;
use App\Services\LLM\DTO\LLMChatRequest;
use App\Services\LLM\DTO\LLMImageContent;
use App\Services\LLM\DTO\LLMTextContent;
use App\Services\LLM\LLMEffectiveCapabilityResolver;
use App\Services\LLM\LLMRouter;
use App\Services\LLM\LLMSettingsRepository;
use App\Services\LLM\LLMTaskProfileResolver;
use App\Services\LLM\RouterAiModelCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $this->assertSame('dynamic_catalog', app(LLMEffectiveCapabilityResolver::class)->source('routerai', 'openai/gpt-4o', \App\Services\LLM\Enums\LLMCapability::IMAGE_INPUT));
        $this->assertSame('routerai_gateway', app(LLMEffectiveCapabilityResolver::class)->source('routerai', 'openai/gpt-4o', \App\Services\LLM\Enums\LLMCapability::PDF_OCR));
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

    public function test_routerai_image_capability_refreshes_once_and_two_requests_use_the_pinned_model(): void
    {
        $catalogCalls = 0;
        Http::fake(static function ($request) use (&$catalogCalls) {
            if (str_ends_with($request->url(), '/models')) {
                $catalogCalls++;
                $inputs = $catalogCalls === 1 ? ['text'] : ['text', 'image'];

                return Http::response(['data' => [[
                    'id' => 'custom/luna-vision',
                    'architecture' => ['input_modalities' => $inputs, 'output_modalities' => ['text']],
                    'streaming' => false,
                ]]], 200);
            }

            return Http::response([
                'provider' => 'routerai-upstream', 'model' => 'custom/luna-vision',
                'choices' => [['message' => ['content' => 'OK']]],
            ], 200);
        });
        app(RouterAiModelCatalogService::class)->snapshot();
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/luna-vision', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $router = app(LLMRouter::class);
        $image = new LLMChatRequest('Test', [new LLMChatMessage('user', [
            new LLMTextContent('Test'), new LLMImageContent('fixture', 'red.png', 'image/png', 'bytes', 1, 1),
        ])]);
        Log::spy();
        $first = iterator_to_array($router->streamChat($image, new LLMCancellationToken(static fn (): bool => false), taskProfile: 'expert_chat'));
        $second = iterator_to_array($router->streamChat($image, new LLMCancellationToken(static fn (): bool => false), taskProfile: 'expert_chat'));

        $this->assertSame(['delta', 'done'], array_map(static fn ($event) => $event->type, $first));
        $this->assertSame(['delta', 'done'], array_map(static fn ($event) => $event->type, $second));
        $this->assertTrue($first[1]->metadata['sync_fallback']);
        $this->assertSame(2, $catalogCalls);
        $chatRequests = Http::recorded(static fn ($request): bool => str_ends_with($request->url(), '/chat/completions'))->values();
        $this->assertCount(2, $chatRequests);
        $this->assertSame('custom/luna-vision', $chatRequests[0][0]['model']);
        $this->assertSame('custom/luna-vision', $chatRequests[1][0]['model']);
        Log::shouldHaveReceived('warning')->with(
            'LLMRouter: RouterAI media capability self-healing decision.',
            \Mockery::on(static fn (array $context): bool => $context['task_profile'] === 'expert_chat'
                && $context['effective_model'] === 'custom/luna-vision'
                && $context['catalog_refreshed'] === true
                && $context['image_input'] === true),
        )->once();
        Log::shouldHaveReceived('info')->with(
            'LLMRouter: RouterAI media request completed.',
            \Mockery::on(static fn (array $context): bool => $context['actual_upstream_provider'] === 'routerai-upstream'
                && $context['actual_upstream_model'] === 'custom/luna-vision'),
        )->twice();
    }

    public function test_routerai_negative_catalog_capability_is_advisory_after_refresh(): void
    {
        $catalogCalls = 0;
        Http::fake(static function ($request) use (&$catalogCalls) {
            if (str_ends_with($request->url(), '/models')) {
                $catalogCalls++;

                return Http::response(['data' => [[
                    'id' => 'custom/luna-advisory',
                    'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                ]]], 200);
            }

            return Http::response(['model' => 'custom/luna-advisory', 'choices' => [['message' => ['content' => 'OK']]]], 200);
        });
        app(RouterAiModelCatalogService::class)->snapshot();
        app(LLMSettingsRepository::class)->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'custom/luna-advisory', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $request = new LLMChatRequest('Test', [new LLMChatMessage('user', [
            new LLMTextContent('Test'), new LLMImageContent('fixture', 'red.png', 'image/png', 'bytes', 1, 1),
        ])]);

        $response = app(LLMRouter::class)->chat($request, taskProfile: 'expert_chat');

        $this->assertSame('OK', $response->content);
        $this->assertSame(2, $catalogCalls);
        Http::assertSent(static fn ($sent): bool => str_ends_with($sent->url(), '/chat/completions'));
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
        $this->catalogFake($models);
        $base = '/api/admin/llm-model-catalog/routerai';
        $this->actingAs($admin, 'sanctum')->getJson($base.'?filter[]=compatible')->assertOk()
            ->assertJsonPath('total', 45)->assertJsonCount(40, 'models');
        $this->getJson($base.'?filter[]=compatible&page=2')->assertOk()->assertJsonCount(5, 'models');
        $this->getJson($base.'?q=model-42&filter[]=compatible')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('models.0.id', 'vendor/model-42');
        Http::assertSentCount(1);
    }

    public function test_price_sort_uses_token_units_across_all_pages_and_preserves_pinned_model_on_refresh(): void
    {
        $admin = User::factory()->create(['id' => 1]);
        $settings = app(LLMSettingsRepository::class);
        $settings->saveTaskProfile('expert_chat', [
            'provider' => 'routerai', 'model' => 'legacy/unknown', 'enabled' => true, 'fallback_policy' => 'none',
        ]);
        $models = [];
        for ($index = 0; $index < 43; $index++) {
            $models[] = [
                'id' => 'vendor/model-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                'pricing' => ['prompt' => $index === 42 ? '0.000001' : '0.000010', 'completion' => '0.000002'],
                'pricing_units' => ['prompt' => $index === 41 ? 'request' : 'token', 'completion' => 'token'],
            ];
        }
        $models[40]['pricing']['completion'] = 'invalid';
        $this->catalogFake($models);
        $base = '/api/admin/llm-model-catalog/routerai';
        $this->actingAs($admin, 'sanctum')->getJson($base.'?sort=typical_cost')->assertOk()
            ->assertJsonPath('models.0.id', 'vendor/model-42')->assertJsonCount(40, 'models');
        $this->getJson($base.'?sort=typical_cost&page=2')->assertOk()
            ->assertJsonPath('models.1.id', 'vendor/model-40')->assertJsonPath('models.2.id', 'vendor/model-41');
        $this->getJson($base.'?sort=input_cost')->assertOk()->assertJsonPath('models.0.id', 'vendor/model-42');
        $this->postJson($base.'/refresh')->assertOk();
        $this->assertSame('legacy/unknown', $settings->getTaskProfile('expert_chat')['model']);
    }

    private function catalogFake(array $models): void
    {
        Http::fake(['*/models' => Http::response(['data' => $models], 200)]);
    }
}
