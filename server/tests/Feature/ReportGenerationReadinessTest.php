<?php

namespace Tests\Feature;

use App\Dto\PositionDto;
use App\Dto\ProjectMetaDto;
use App\Dto\ReportDto;
use App\Dto\TotalsDto;
use App\Models\EvidenceArtifact;
use App\Models\Project;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Service\ReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class ReportGenerationReadinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_project_blocks_estimate_revision_creation(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->mockReportService($project, $this->report($project));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions");

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'empty_project');

        $this->assertSame(0, $project->revisions()->count());
    }

    public function test_empty_project_blocks_price_evidence_run_creation(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->mockReportService($project, $this->report($project));

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions/run");

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'empty_project');

        $this->assertSame(0, $project->revisionRuns()->count());
    }

    public function test_repeated_estimate_revision_creation_reuses_unchanged_revision(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->mockReportService($project, $this->report($project, withContent: true));

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions");

        $first->assertStatus(201);
        $first->assertJsonPath('status', 'created');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions");

        $second->assertStatus(200);
        $second->assertJsonPath('status', 'unchanged');
        $second->assertJsonPath('number', $first->json('number'));

        $this->assertSame(1, $project->revisions()->count());
    }

    public function test_repeated_price_evidence_finalize_reuses_unchanged_revision(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->mockReportService($project, $this->report($project, withContent: true));

        $firstRun = $this->makeReadyInternalRun($project, $user, 'Доставка', 500);

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions/run/{$firstRun->id}/finalize");

        $first->assertStatus(200);
        $first->assertJsonPath('status', 'created');

        $secondRun = $this->makeReadyInternalRun($project, $user, 'Доставка', 500);

        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions/run/{$secondRun->id}/finalize");

        $second->assertStatus(200);
        $second->assertJsonPath('status', 'unchanged');
        $second->assertJsonPath('revision.number', $first->json('revision.number'));

        $this->assertSame(1, $project->revisions()->count());
    }

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'READY-' . uniqid(),
            'expert_name' => 'Readiness Tester',
            'address' => 'Test Address',
        ]);
    }

    private function mockReportService(Project $project, ReportDto $report): void
    {
        $reportService = Mockery::mock(ReportService::class);
        $reportService
            ->shouldReceive('buildReport')
            ->with(Mockery::on(fn (Project $actual) => (int) $actual->id === (int) $project->id))
            ->andReturn($report);

        $this->app->instance(ReportService::class, $reportService);
    }

    private function makeReadyInternalRun(Project $project, User $user, string $name, float $amount): RevisionRun
    {
        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
            'ok_items' => 1,
            'failed_items' => 0,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => 'expense',
            'evidence_subject_type' => null,
            'evidence_subject_id' => null,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => 'internal',
            'cost_driver_type' => 'expense',
            'extracted_price' => $amount,
            'currency' => 'RUB',
            'extracted_name' => $name,
            'trust_score' => 50,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return $run;
    }

    private function report(Project $project, bool $withContent = false): ReportDto
    {
        $totals = new TotalsDto();
        if ($withContent) {
            $totals->materials_cost = 100.0;
            $totals->subtotal = 100.0;
            $totals->total = 100.0;
            $totals->grand_total = 100.0;
            $totals->total_amount = 100.0;
        }

        return new ReportDto(
            project: new ProjectMetaDto(
                id: $project->id,
                number: $project->number,
                expert_name: $project->expert_name ?? '',
                address: $project->address ?? '',
                waste_coefficient: 1.0,
                repair_coefficient: 1.0,
            ),
            positions: $withContent ? [
                new PositionDto(
                    id: 1,
                    project_id: $project->id,
                    detail_type_id: null,
                    material_id: null,
                    edge_material_id: null,
                    edge_scheme: null,
                    quantity: 1,
                    width: 100,
                    length: 100,
                    height: null,
                    detail_name: 'Тестовая позиция',
                ),
            ] : [],
            totals: $totals,
        );
    }
}
