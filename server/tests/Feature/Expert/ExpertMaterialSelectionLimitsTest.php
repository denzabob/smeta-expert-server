<?php

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpertMaterialSelectionLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_selection_limits_are_authenticated_project_scoped_and_use_runtime_config(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $owner->id,
            'name' => 'Проект',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $url = "/api/expert/projects/{$project->public_id}/material-selection-limits";

        $this->getJson($url)->assertUnauthorized();

        config()->set('expert.material_context.max_materials_per_message', 7);
        config()->set('expert.vision.max_images_per_message', 3);

        $this->actingAs($owner, 'sanctum')->getJson($url)
            ->assertOk()
            ->assertExactJson([
                'max_materials_per_message' => 7,
                'max_images_per_message' => 3,
            ]);

        $this->actingAs($other, 'sanctum')->getJson($url)->assertForbidden();
    }

    public function test_zero_message_material_limit_is_reported_as_unlimited(): void
    {
        $owner = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $owner->id,
            'name' => 'Проект',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        config()->set('expert.material_context.max_materials_per_message', 0);

        $this->actingAs($owner, 'sanctum')->getJson("/api/expert/projects/{$project->public_id}/material-selection-limits")
            ->assertOk()
            ->assertJsonPath('max_materials_per_message', 0);
    }
}
