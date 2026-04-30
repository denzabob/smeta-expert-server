<?php

namespace Tests\Feature\Billing;

use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectWorkspaceReadOnlyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_project_is_allowed_when_owned_project_limit_is_exceeded(): void
    {
        [$user, $project] = $this->makeOverLimitWorkspace();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('billing_workspace.read_only', true)
            ->assertJsonPath('billing_workspace.reason', 'active_projects_limit_exceeded')
            ->assertJsonPath('billing_workspace.limit_key', BillingCodes::CAP_PROJECTS_MAX_OWNED)
            ->assertJsonPath('billing_workspace.limit', 1)
            ->assertJsonPath('billing_workspace.owned_projects', 2);
    }

    public function test_owned_project_limit_counts_archived_projects(): void
    {
        [$user, $project, $archivedProject] = $this->makeWorkspace(ownedProjects: 2, limit: 1);
        $archivedProject->forceFill(['archived_at' => now()])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('billing_workspace.read_only', true)
            ->assertJsonPath('billing_workspace.owned_projects', 2)
            ->assertJsonPath('billing_workspace.active_projects', 1);
    }

    public function test_physically_deleted_projects_are_not_counted(): void
    {
        [$user, $project, $deletedProject] = $this->makeWorkspace(ownedProjects: 2, limit: 1);
        $deletedProject->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('billing_workspace.read_only', false)
            ->assertJsonPath('billing_workspace.owned_projects', 1);
    }

    public function test_projects_max_owned_falls_back_to_projects_max_active_limit(): void
    {
        [$user, $project] = $this->makeOverLimitWorkspace(useLegacyLimit: true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('billing_workspace.read_only', true)
            ->assertJsonPath('billing_workspace.limit_key', BillingCodes::CAP_PROJECTS_MAX_ACTIVE)
            ->assertJsonPath('billing_workspace.limit', 1)
            ->assertJsonPath('billing_workspace.owned_projects', 2);
    }

    public function test_project_create_is_blocked_when_owned_project_limit_is_reached(): void
    {
        [$user] = $this->makeWorkspace(ownedProjects: 1, limit: 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILLING-CREATE-BLOCKED-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ])
            ->assertStatus(423)
            ->assertJsonPath('billing.reason', 'active_projects_limit_reached')
            ->assertJsonPath('billing.limit_key', BillingCodes::CAP_PROJECTS_MAX_OWNED)
            ->assertJsonPath('billing.limit', 1)
            ->assertJsonPath('billing.owned_projects', 1)
            ->assertJsonPath('billing.read_only', false);

        $event = BillingGateEvent::query()
            ->where('user_id', $user->id)
            ->where('capability', BillingCodes::CAP_PROJECTS_MAX_OWNED)
            ->firstOrFail();

        $this->assertTrue($event->would_block);
        $this->assertTrue($event->enforced);
        $this->assertSame('projects.create', $event->context_json['action'] ?? null);
        $this->assertSame(BillingCodes::CAP_PROJECTS_MAX_OWNED, $event->context_json['limit_key'] ?? null);
        $this->assertSame(1, $event->context_json['allowed'] ?? null);
        $this->assertSame(1, $event->context_json['actual'] ?? null);
    }

    public function test_checkout_mode_logs_would_block_but_allows_project_create(): void
    {
        [$user] = $this->makeWorkspace(ownedProjects: 1, limit: 1, enforced: false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILLING-CREATE-ALLOWED-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ])
            ->assertCreated();

        $this->assertSame(2, Project::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('billing_gate_events', [
            'user_id' => $user->id,
            'capability' => BillingCodes::CAP_PROJECTS_MAX_OWNED,
            'would_block' => true,
            'enforced' => false,
        ]);
    }

    public function test_project_update_is_blocked_when_owned_project_limit_is_exceeded(): void
    {
        [$user, $project] = $this->makeOverLimitWorkspace();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'number' => 'BILLING-READ-ONLY',
            ])
            ->assertStatus(423)
            ->assertJsonPath('message', $this->expectedMessage(limit: 1, ownedProjects: 2))
            ->assertJsonPath('billing.reason', 'active_projects_limit_exceeded')
            ->assertJsonPath('billing.read_only', true)
            ->assertJsonPath('billing.limit', 1)
            ->assertJsonPath('billing.owned_projects', 2);
    }

    public function test_project_position_create_is_blocked_when_owned_project_limit_is_exceeded(): void
    {
        [$user, $project] = $this->makeOverLimitWorkspace();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/positions", [
                'quantity' => 1,
                'width' => 100,
                'length' => 100,
            ])
            ->assertStatus(423)
            ->assertJsonPath('message', $this->expectedMessage(limit: 1, ownedProjects: 2))
            ->assertJsonPath('billing.reason', 'active_projects_limit_exceeded')
            ->assertJsonPath('billing.read_only', true);
    }

    public function test_project_archive_is_allowed_but_does_not_restore_editing(): void
    {
        [$user, $project, $extraProject] = $this->makeOverLimitWorkspace();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/projects/{$extraProject->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Проект архивирован');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'number' => 'BILLING-STILL-READ-ONLY',
            ])
            ->assertStatus(423)
            ->assertJsonPath('billing.owned_projects', 2);
    }

    public function test_visible_or_checkout_modes_do_not_block_project_editing(): void
    {
        [$user, $project] = $this->makeOverLimitWorkspace(enforced: false);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'number' => 'BILLING-NOT-ENFORCED',
            ])
            ->assertOk()
            ->assertJsonPath('number', 'BILLING-NOT-ENFORCED');
    }

    public function test_superadmin_owner_does_not_create_project_gate_event(): void
    {
        User::query()->whereKey(1)->delete();
        [$user] = $this->makeWorkspace(ownedProjects: 1, limit: 1, userAttributes: ['id' => 1]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILLING-SUPERADMIN-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ])
            ->assertStatus(423);

        $this->assertSame(0, BillingGateEvent::query()->where('user_id', 1)->count());
    }

    private function makeOverLimitWorkspace(bool $enforced = true, bool $useLegacyLimit = false): array
    {
        return $this->makeWorkspace(ownedProjects: 2, limit: 1, enforced: $enforced, useLegacyLimit: $useLegacyLimit);
    }

    private function makeWorkspace(
        int $ownedProjects,
        int $limit,
        bool $enforced = true,
        bool $useLegacyLimit = false,
        array $userAttributes = [],
    ): array {
        config([
            'billing.enabled' => true,
            'billing.enforce_limits' => $enforced,
            'billing.log_only' => ! $enforced,
            'billing.default_plan' => 'free_default',
        ]);

        BillingPlan::query()->updateOrCreate(['code' => 'free_default'], [
            'code' => 'free_default',
            'name' => 'Free',
            'is_active' => true,
            'metadata_json' => [
                'limits' => [
                    $useLegacyLimit ? BillingCodes::CAP_PROJECTS_MAX_ACTIVE : BillingCodes::CAP_PROJECTS_MAX_OWNED => $limit,
                ],
            ],
        ]);

        $user = User::factory()->create($userAttributes);
        $projects = [];

        for ($index = 0; $index < $ownedProjects; $index++) {
            $projects[] = Project::query()->create([
                'user_id' => $user->id,
                'number' => uniqid('BILLING-PROJECT-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ]);
        }

        return [$user, $projects[0] ?? null, $projects[1] ?? null];
    }

    private function expectedMessage(int $limit, int $ownedProjects): string
    {
        return "Доступен режим просмотра. На текущем тарифе доступно проектов: {$limit}. Сейчас в аккаунте: {$ownedProjects}. Вы можете просматривать проекты, но создание и редактирование временно ограничены. Выберите подходящий тариф, чтобы продолжить работу.";
    }
}
