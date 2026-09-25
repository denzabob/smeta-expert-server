<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertConversationMaterialStateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpertConversationMaterialStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_v3_state_keeps_attachment_order_roles_and_active_candidates_separate(): void
    {
        $conversation = $this->conversation();
        $a = $this->material($conversation->project, 'A.pdf');
        $b = $this->material($conversation->project, 'B.jpg');
        $c = $this->material($conversation->project, 'C.pdf');
        $active = $this->material($conversation->project, 'Only active.pdf');
        $conversation->activeMaterials()->sync([$active->id]);
        $message = $this->message($conversation, $this->v3Snapshot([
            [$a->public_id, 'primary'],
            [$b->public_id, 'primary'],
            [$c->public_id, 'comparison'],
        ], true));
        $this->attach($message, $b, 0);
        $this->attach($message, $a, 1);

        $state = app(ExpertConversationMaterialStateBuilder::class)->build($conversation);

        $this->assertSame($message->public_id, $state->lastCurrentBatch?->messageId);
        $this->assertSame([$b->public_id, $a->public_id], $state->lastCurrentBatch?->orderedMaterialIds);
        $this->assertNotNull($state->lastCurrentBatch?->createdAt);
        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $state->lastResolvedSourceSet);
        $this->assertSame([$a->public_id, $b->public_id], $state->lastPrimarySourceSet);
        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $state->focusedSourceSet);
        $this->assertSame([$a->public_id, $b->public_id], $state->focusedPrimaryIds);
        $this->assertSame($message->public_id, $state->focusedAtMessageId);
        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $state->lastComparisonSourceSet);
        $this->assertSame([$active->public_id], $state->activeResearchSet);
        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $state->recentSourceSets[0]->materialIds);
    }

    public function test_v2_snapshot_preserves_used_ids_without_inventing_roles(): void
    {
        $conversation = $this->conversation();
        $a = $this->material($conversation->project, 'A.pdf');
        $b = $this->material($conversation->project, 'B.pdf');
        $this->message($conversation, [
            'version' => 2,
            'current_material_ids' => [$a->public_id],
            'active_material_ids' => [$b->public_id],
            'resolved_material_ids' => [$a->public_id, $b->public_id],
        ]);

        $state = app(ExpertConversationMaterialStateBuilder::class)->build($conversation);

        $this->assertSame([$a->public_id, $b->public_id], $state->lastResolvedSourceSet);
        $this->assertSame([], $state->lastPrimarySourceSet);
        $this->assertSame([], $state->focusedSourceSet);
        $this->assertSame([], $state->focusedPrimaryIds);
        $this->assertSame([], $state->lastComparisonSourceSet);
        $this->assertSame(2, $state->recentSourceSets[0]->snapshotVersion);
        $this->assertSame([], $state->activeResearchSet);
    }

    public function test_five_previous_attachments_do_not_replace_a_single_resolved_focus(): void
    {
        $conversation = $this->conversation();
        $images = array_map(fn (int $number): ExpertProjectMaterial => $this->material($conversation->project, "{$number}.jpg"), [1, 2, 3]);
        $other = $this->material($conversation->project, 'Дополнительная экспертиза.pdf');
        $focused = $this->material($conversation->project, 'Заключение Дягилевой.pdf');
        $message = $this->message($conversation, $this->v3Snapshot([[$focused->public_id, 'primary']]));
        foreach ([...$images, $other, $focused] as $position => $material) {
            $this->attach($message, $material, $position);
        }
        $this->message($conversation, [
            'version' => 3,
            'selected_sources' => [],
            'ambiguity' => ['ambiguous' => true],
        ]);

        $state = app(ExpertConversationMaterialStateBuilder::class)->build($conversation);

        $this->assertCount(5, $state->lastCurrentBatch?->orderedMaterialIds ?? []);
        $this->assertSame([$focused->public_id], $state->focusedSourceSet);
        $this->assertSame([$focused->public_id], $state->focusedPrimaryIds);
        $this->assertSame($message->public_id, $state->focusedAtMessageId);
    }

    public function test_assistant_text_and_library_upload_order_do_not_change_structural_state(): void
    {
        $conversation = $this->conversation();
        $a = $this->material($conversation->project, 'A.pdf');
        $message = $this->message($conversation, $this->v3Snapshot([[$a->public_id, 'primary']]));
        $this->attach($message, $a, 0);
        $builder = app(ExpertConversationMaterialStateBuilder::class);
        $before = $builder->build($conversation);

        $assistant = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Ложная связь с другим документом.']);
        $second = $this->material($conversation->project, 'Unrelated 2.pdf');
        $first = $this->material($conversation->project, 'Unrelated 1.pdf');
        $after = $builder->build($conversation);
        $this->assertEquals($before, $after);

        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second->forceFill(['created_at' => now()->addMinute()])->save();
        $this->assertEquals($before, $builder->build($conversation));

        $assistant->update(['content' => '']);
        $this->assertEquals($before, $builder->build($conversation));
    }

    public function test_recent_source_sets_are_bounded_and_a_new_message_updates_state(): void
    {
        config()->set('expert.context.recent_source_sets_limit', 2);
        $conversation = $this->conversation();
        $materials = [
            $this->material($conversation->project, 'A.pdf'),
            $this->material($conversation->project, 'B.pdf'),
            $this->material($conversation->project, 'C.pdf'),
        ];
        $builder = app(ExpertConversationMaterialStateBuilder::class);
        foreach ($materials as $index => $material) {
            $this->message($conversation, $this->v3Snapshot([[$material->public_id, 'primary']]));
            $this->assertSame([$material->public_id], $builder->build($conversation)->lastResolvedSourceSet);
            if ($index < 2) {
                $this->message($conversation, $this->v3Snapshot([]));
            }
        }
        $state = $builder->build($conversation);
        $this->assertCount(2, $state->recentSourceSets);
        $this->assertSame([$materials[2]->public_id], $state->recentSourceSets[0]->materialIds);
        $this->assertSame([$materials[1]->public_id], $state->recentSourceSets[1]->materialIds);
        $this->assertSame([$materials[2]->public_id], $state->focusedPrimaryIds);
    }

    private function conversation(): ExpertConversation
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Material state',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return $project->conversations()->create(['title' => 'Conversation']);
    }

    private function material(ExpertProject $project, string $name): ExpertProjectMaterial
    {
        return $project->materials()->create([
            'uploaded_by' => $project->user_id,
            'original_name' => $name,
            'storage_path' => 'expert/state/'.$name,
            'mime_type' => str_ends_with($name, '.jpg') ? 'image/jpeg' : 'application/pdf',
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => 1,
            'category' => 'research',
            'status' => 'uploaded',
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function message(ExpertConversation $conversation, array $snapshot): ExpertMessage
    {
        return $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Запрос',
            'metadata' => ['expert_context_snapshot' => $snapshot],
        ]);
    }

    private function attach(ExpertMessage $message, ExpertProjectMaterial $material, int $position): void
    {
        $message->attachments()->create([
            'expert_project_material_id' => $material->id,
            'position' => $position,
            'material_public_id_snapshot' => $material->public_id,
            'original_name_snapshot' => $material->original_name,
            'mime_type_snapshot' => $material->mime_type,
            'size_snapshot' => 1,
        ]);
    }

    /** @param list<array{string, string}> $sources @return array<string, mixed> */
    private function v3Snapshot(array $sources, bool $crossDocument = false): array
    {
        return [
            'version' => 3,
            'selected_sources' => array_map(static fn (array $source): array => [
                'material_id' => $source[0],
                'role' => $source[1],
                'origin' => 'current',
                'reason_code' => 'current_batch_reference',
            ], $sources),
            'task_intent' => ['cross_document' => $crossDocument],
        ];
    }
}
