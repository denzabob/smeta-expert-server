<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertAiRun;
use App\Models\Expert\ExpertAiRunEvent;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminExpertAiRunsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_run_list_or_detail(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $run = $this->makeRun($user);

        $this->actingAs($user, 'sanctum')->getJson('/api/admin/expert-ai-runs')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/admin/expert-ai-runs/'.$run->run_id)->assertForbidden();
    }

    public function test_admin_gets_paginated_runs_with_server_side_filters_and_default_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['name' => 'Автор запуска']);
        $older = $this->makeRun($owner, [
            'status' => 'failed', 'stage' => 'context', 'error_code' => 'context_unavailable',
            'started_at' => now()->subDays(2), 'provider' => 'routerai', 'model' => 'openai/gpt-5.6-sol',
        ]);
        $newer = $this->makeRun($owner, [
            'status' => 'completed', 'stage' => 'persistence', 'error_code' => null,
            'started_at' => now()->subDay(), 'provider' => 'routerai', 'model' => 'openai/gpt-5.6-sol',
        ]);
        $other = $this->makeRun(User::factory()->create(), [
            'status' => 'failed', 'stage' => 'provider', 'error_code' => 'provider_unavailable',
            'started_at' => now(), 'provider' => 'other', 'model' => 'other/model',
        ]);

        $pageOne = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?per_page=1&page=1');
        $pageOne->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('data.0.run_id', $other->run_id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.run_id', $newer->run_id);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?per_page=1&page=1&status=completed&user_id='.$owner->id.'&project_id='.$newer->expert_project_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.run_id', $newer->run_id)
            ->assertJsonPath('data.0.user.name', 'Автор запуска')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1);

        $filtered = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?status=failed&stage=context&error_code=context_unavailable&date_from='.now()->subDays(3)->format('Y-m-d').'&date_to='.now()->subDay()->format('Y-m-d'));
        $filtered->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.run_id', $older->run_id);

        $conversationFiltered = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?conversation_id='.$newer->expert_conversation_id.'&provider=routerai&model=openai%2Fgpt-5.6-sol&requested_mode=auto');
        $conversationFiltered->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.run_id', $newer->run_id);

        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?per_page=10');
        $all->assertOk()->assertJsonPath('data.0.run_id', $other->run_id);
    }

    public function test_run_id_filter_supports_exact_and_prefix_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run = $this->makeRun(User::factory()->create());

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?run_id='.substr($run->run_id, 0, 8))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.run_id', $run->run_id);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs?run_id='.$run->run_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.run_id', $run->run_id);
    }

    public function test_detail_returns_events_in_sequence_and_excludes_unsafe_payload_and_run_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $run = $this->makeRun(User::factory()->create(), [
            'metadata' => ['secret' => 'private metadata must stay private'],
        ]);
        foreach ([2, 1] as $sequence) {
            ExpertAiRunEvent::query()->create([
                'expert_ai_run_id' => $run->id,
                'run_id' => $run->run_id,
                'seq' => $sequence,
                'source' => 'lifecycle',
                'level' => $sequence === 2 ? 'error' : 'info',
                'event_code' => $sequence === 2 ? 'run.failed' : 'run.created',
                'stage' => $sequence === 2 ? 'provider' : 'created',
                'status' => $sequence === 2 ? 'failed' : 'created',
                'payload' => $sequence === 2 ? [
                    'error_code' => 'provider_unavailable',
                    'retryable' => true,
                    'exception_class' => 'App\\Services\\ProviderException',
                    'document_content' => 'CONFIDENTIAL_DOCUMENT_TEXT',
                    'prompt' => 'CONFIDENTIAL_PROMPT_TEXT',
                    'api_key' => 'CONFIDENTIAL_API_KEY',
                ] : ['requested_mode' => 'auto'],
                'created_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-ai-runs/'.$run->run_id);
        $response->assertOk()
            ->assertJsonPath('run.run_id', $run->run_id)
            ->assertJsonPath('events.0.seq', 1)
            ->assertJsonPath('events.0.event_code', 'run.created')
            ->assertJsonPath('events.1.seq', 2)
            ->assertJsonPath('events.1.payload.error_code', 'provider_unavailable')
            ->assertJsonPath('events.1.payload.retryable', true)
            ->assertJsonMissingPath('run.metadata');

        $body = $response->getContent();
        $this->assertStringNotContainsString('CONFIDENTIAL_DOCUMENT_TEXT', $body);
        $this->assertStringNotContainsString('CONFIDENTIAL_PROMPT_TEXT', $body);
        $this->assertStringNotContainsString('CONFIDENTIAL_API_KEY', $body);
        $this->assertStringNotContainsString('private metadata must stay private', $body);
    }

    private function makeRun(User $owner, array $overrides = []): ExpertAiRun
    {
        $project = ExpertProject::create([
            'user_id' => $owner->id,
            'name' => 'Проверочный проект',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $conversation = $project->conversations()->create(['title' => 'Проверочный диалог']);

        return ExpertAiRun::query()->create(array_merge([
            'run_id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'expert_project_id' => $project->id,
            'expert_conversation_id' => $conversation->id,
            'client_message_id' => (string) Str::uuid(),
            'status' => 'running',
            'stage' => 'created',
            'requested_mode' => 'auto',
            'selected_material_count' => 1,
            'persisted_material_count' => 1,
            'resolved_material_count' => 1,
            'active_material_count' => 1,
            'provider' => null,
            'model' => null,
            'started_at' => now(),
            'retryable' => false,
            'metadata' => [],
        ], $overrides));
    }
}
