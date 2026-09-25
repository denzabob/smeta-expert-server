<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertTaskIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpertContextPlannerIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_comparison_stays_ambiguous_without_structural_sources(): void
    {
        $conversation = $this->conversation();
        $first = $this->material($conversation->project, 'Дополнительная экспертиза.pdf');
        $second = $this->material($conversation->project, 'Заключение дягилевой.pdf');
        $conversation->activeMaterials()->sync([$first->id, $second->id]);

        $plan = app(\App\Services\Expert\ExpertContextPlanner::class)->plan(
            $conversation,
            'сравни эти две экспертизы',
            [],
            [],
        );

        $this->assertSame('retrieval_multi', $plan->scope);
        $this->assertSame(ExpertTaskIntent::COMPARE, $plan->intent?->taskType);
        $this->assertSame(ExpertTaskIntent::ACTIVE, $plan->intent?->materialScope);
        $this->assertTrue($plan->intent?->crossDocument);
        $this->assertSame([], $plan->resolvedMaterials);
        $this->assertTrue($plan->diagnostics['requires_material_disambiguation']);
        $this->assertCount(2, $plan->candidatePool?->candidates ?? []);
    }

    public function test_current_attachments_remain_current_for_extraction(): void
    {
        $conversation = $this->conversation();
        $materials = [
            $this->material($conversation->project, '3.jpg', 'image/jpeg'),
            $this->material($conversation->project, '1.jpg', 'image/jpeg'),
            $this->material($conversation->project, '2.jpg', 'image/jpeg'),
        ];

        $ids = array_map(static fn (ExpertProjectMaterial $material): string => $material->public_id, $materials);
        $plan = app(\App\Services\Expert\ExpertContextPlanner::class)->plan(
            $conversation,
            'какие вопросы стоят перед экспертом',
            $ids,
            [],
        );

        $this->assertSame(ExpertTaskIntent::CURRENT, $plan->intent?->materialScope);
        $this->assertSame(ExpertTaskIntent::EXTRACT, $plan->intent?->taskType);
        $this->assertSame($ids, $plan->resolvedMaterials);
        $this->assertSame('targeted_multi', $plan->scope);
    }

    public function test_exhaustive_active_find_is_not_retrieval(): void
    {
        $conversation = $this->conversation();
        $first = $this->material($conversation->project, 'A.pdf');
        $second = $this->material($conversation->project, 'B.pdf');
        $conversation->activeMaterials()->sync([$first->id, $second->id]);

        $plan = app(\App\Services\Expert\ExpertContextPlanner::class)->plan(
            $conversation,
            'найди все упоминания ГОСТ 16371',
            [],
            [],
        );

        $this->assertSame('exhaustive_multi', $plan->scope);
        $this->assertSame('exhaustive', $plan->coverageMode);
        $this->assertSame(ExpertTaskIntent::FIND, $plan->intent?->taskType);
        $this->assertSame([], $plan->resolvedMaterials);
    }

    public function test_unrelated_active_candidate_does_not_change_explicit_current_sources(): void
    {
        $conversation = $this->conversation();
        $current = $this->material($conversation->project, 'Current.pdf');
        $planner = app(\App\Services\Expert\ExpertContextPlanner::class);
        $before = $planner->plan($conversation, 'Сравни приложенный документ', [$current->public_id], []);

        $unrelated = $this->material($conversation->project, 'Unrelated.pdf');
        $conversation->activeMaterials()->sync([$unrelated->id]);
        $after = $planner->plan($conversation, 'Сравни приложенный документ', [$current->public_id], []);

        $this->assertSame([$current->public_id], $before->resolvedMaterials);
        $this->assertSame($before->resolvedMaterials, $after->resolvedMaterials);
        $this->assertSame([$unrelated->public_id], $after->activeMaterials);
    }

    public function test_exhaustive_query_without_structural_sources_does_not_select_project_library(): void
    {
        $conversation = $this->conversation();
        $this->material($conversation->project, 'A.pdf');
        $this->material($conversation->project, 'B.pdf');

        $plan = app(\App\Services\Expert\ExpertContextPlanner::class)->plan(
            $conversation,
            'Найди все упоминания ГОСТ',
            [],
            [],
        );

        $this->assertSame([], $plan->resolvedMaterials);
        $this->assertSame('exhaustive_multi', $plan->scope);
        $this->assertTrue($plan->diagnostics['requires_material_disambiguation']);
        $this->assertCount(2, $plan->candidatePool?->candidates ?? []);
    }

    private function conversation(): ExpertConversation
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Intent test project',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return $project->conversations()->create(['title' => 'Intent test conversation']);
    }

    private function material(ExpertProject $project, string $name, string $mime = 'application/pdf'): ExpertProjectMaterial
    {
        return $project->materials()->create([
            'uploaded_by' => $project->user_id,
            'original_name' => $name,
            'storage_path' => 'expert/intent/'.$name,
            'mime_type' => $mime,
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => 100,
            'category' => 'research',
            'status' => 'uploaded',
        ]);
    }
}
