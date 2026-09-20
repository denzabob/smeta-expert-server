<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpertMessageFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_rate_change_remove_and_reopen_feedback_with_model_snapshot(): void
    {
        [, $owner, $conversation, $assistant] = $this->fixture();
        $url = '/api/expert/messages/'.$assistant->public_id.'/feedback';

        $this->actingAs($owner, 'sanctum')->putJson($url, [
            'rating' => 'negative', 'reason_code' => 'material_analysis_error', 'comment' => 'Пропущен документ.',
        ])->assertOk()->assertJsonPath('reason_code', 'material_analysis_error');
        $this->assertDatabaseHas('expert_message_feedback', [
            'message_id' => $assistant->id, 'user_id' => $owner->id,
            'provider' => 'routerai', 'model' => 'openai/gpt-5.6-sol',
            'run_id' => $assistant->metadata['run_id'],
        ]);
        $this->actingAs($owner, 'sanctum')->getJson('/api/expert/conversations/'.$conversation->public_id.'/messages')
            ->assertOk()->assertJsonPath('data.1.feedback.rating', 'negative');

        $this->actingAs($owner, 'sanctum')->putJson($url, ['rating' => 'positive'])->assertOk()
            ->assertJsonPath('reason_code', null);
        $this->assertDatabaseCount('expert_message_feedback', 1);
        $this->actingAs($owner, 'sanctum')->deleteJson($url)->assertNoContent();
        $this->assertDatabaseCount('expert_message_feedback', 0);
    }

    public function test_other_user_cannot_read_or_change_feedback_and_admin_sees_negative_detail(): void
    {
        [$admin, $owner, $conversation, $assistant] = $this->fixture();
        $stranger = User::factory()->create();
        $url = '/api/expert/messages/'.$assistant->public_id.'/feedback';
        $this->actingAs($owner, 'sanctum')->putJson($url, ['rating' => 'negative', 'reason_code' => 'too_slow', 'comment' => 'Долго.'])->assertOk();

        $this->actingAs($stranger, 'sanctum')->putJson($url, ['rating' => 'positive'])->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->deleteJson($url)->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/expert/conversations/'.$conversation->public_id.'/messages')->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/admin/expert-feedback')->assertForbidden();

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-feedback');
        $list->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.comment', 'Долго.');
        $feedbackId = $list->json('data.0.id');
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/expert-feedback/'.$feedbackId)
            ->assertOk()->assertJsonPath('request', 'Проверь документ.')
            ->assertJsonPath('answer', 'Ответ по документу.')
            ->assertJsonPath('model', 'openai/gpt-5.6-sol');
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['id' => 1]);
        $owner = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $owner->id, 'name' => 'Отзыв', 'domain' => 'other', 'work_type' => 'other']);
        $conversation = $project->conversations()->create(['title' => 'Чат']);
        $userMessage = $conversation->messages()->create(['role' => 'user', 'content' => 'Проверь документ.']);
        $assistant = $conversation->messages()->create([
            'role' => 'assistant', 'content' => 'Ответ по документу.',
            'metadata' => [
                'in_reply_to' => $userMessage->public_id,
                'generation_status' => 'completed',
                'provider' => 'routerai', 'model' => 'openai/gpt-5.6-sol',
                'run_id' => (string) Str::uuid(), 'service_tier' => 'priority',
            ],
        ]);

        return [$admin, $owner, $conversation, $assistant];
    }
}
