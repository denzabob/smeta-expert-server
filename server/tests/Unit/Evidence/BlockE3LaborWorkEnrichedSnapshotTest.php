<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectLaborWorkStep;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block E3 — enriched labor_work snapshot fields in finalize().
 *
 * Verifies that finalize() produces labor_work justification rows containing:
 *   - labor_work_hours
 *   - labor_work_basis
 *   - labor_work_note
 *   - labor_work_total_cost
 *   - labor_work_steps (with or without steps)
 *
 * Also verifies backward compatibility: old snapshots without these keys
 * render the PDF template without errors (no new required keys introduced).
 */
class BlockE3LaborWorkEnrichedSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. finalize() includes enriched labor_work fields ──────

    public function test_finalize_labor_work_snapshot_includes_enriched_fields(): void
    {
        config(['smeta.evidence.labor_work_enabled' => true]);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E3-A-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $laborWork = ProjectLaborWork::create([
            'project_id' => $project->id,
            'title' => 'Сборка корпуса',
            'basis' => 'ТУ-2024/1',
            'hours' => 3.5,
            'hours_source' => 'manual',
            'note' => 'Включая проверку',
            'sort_order' => 0,
            'rate_per_hour' => 400.0,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'evidence_subject_type' => 'project_labor_work',
            'evidence_subject_id' => $laborWork->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'extracted_price' => 400.0,
            'currency' => 'RUB',
            'extracted_name' => $laborWork->title,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 88881;
        $fakeRevision->number = 1;

        $this->mock(SnapshotService::class, function ($mock) use (&$captured, $fakeRevision) {
            $mock->shouldReceive('createSnapshot')
                ->once()
                ->withArgs(function ($proj, $userId, $extra) use (&$captured) {
                    $captured = $extra;
                    return true;
                })
                ->andReturn($fakeRevision);
        });

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $response->assertStatus(200);

        $justifications = $captured['price_justifications'] ?? [];
        $lwRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::LABOR_WORK);

        $this->assertNotNull($lwRow, 'Should have a labor_work justification row');

        // Enriched fields must be present
        $this->assertArrayHasKey('labor_work_hours', $lwRow);
        $this->assertArrayHasKey('labor_work_basis', $lwRow);
        $this->assertArrayHasKey('labor_work_note', $lwRow);
        $this->assertArrayHasKey('labor_work_total_cost', $lwRow);
        $this->assertArrayHasKey('labor_work_steps', $lwRow);

        // Values
        $this->assertEquals(3.5, $lwRow['labor_work_hours']);
        $this->assertEquals('ТУ-2024/1', $lwRow['labor_work_basis']);
        $this->assertEquals('Включая проверку', $lwRow['labor_work_note']);
        $this->assertEquals(1400.0, $lwRow['labor_work_total_cost']); // 3.5 × 400.0
        $this->assertIsArray($lwRow['labor_work_steps']);
        $this->assertEmpty($lwRow['labor_work_steps']); // no steps created
    }

    // ── 2. Steps are captured in snapshot when present ─────────

    public function test_finalize_labor_work_snapshot_with_steps(): void
    {
        config(['smeta.evidence.labor_work_enabled' => true]);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E3-B-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $laborWork = ProjectLaborWork::create([
            'project_id' => $project->id,
            'title' => 'Установка петель',
            'hours' => 2.0,
            'hours_source' => 'from_steps',
            'sort_order' => 0,
            'rate_per_hour' => 500.0,
        ]);

        ProjectLaborWorkStep::create([
            'project_labor_work_id' => $laborWork->id,
            'title' => 'Разметка',
            'hours' => '0.5',
            'sort_order' => 0,
        ]);
        ProjectLaborWorkStep::create([
            'project_labor_work_id' => $laborWork->id,
            'title' => 'Сверление',
            'hours' => '0.8',
            'basis' => 'ГОСТ 12345',
            'sort_order' => 1,
        ]);
        ProjectLaborWorkStep::create([
            'project_labor_work_id' => $laborWork->id,
            'title' => 'Крепёж',
            'hours' => '0.7',
            'sort_order' => 2,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'evidence_subject_type' => 'project_labor_work',
            'evidence_subject_id' => $laborWork->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'extracted_price' => 500.0,
            'currency' => 'RUB',
            'extracted_name' => $laborWork->title,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 88882;
        $fakeRevision->number = 1;

        $this->mock(SnapshotService::class, function ($mock) use (&$captured, $fakeRevision) {
            $mock->shouldReceive('createSnapshot')
                ->once()
                ->withArgs(function ($proj, $userId, $extra) use (&$captured) {
                    $captured = $extra;
                    return true;
                })
                ->andReturn($fakeRevision);
        });

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $response->assertStatus(200);

        $justifications = $captured['price_justifications'] ?? [];
        $lwRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::LABOR_WORK);

        $this->assertNotNull($lwRow, 'Should have a labor_work justification row');

        // Steps should be captured
        $this->assertCount(3, $lwRow['labor_work_steps']);
        $this->assertEquals('Разметка', $lwRow['labor_work_steps'][0]['title']);
        $this->assertEquals(0.5, $lwRow['labor_work_steps'][0]['hours']);
        $this->assertEquals('Сверление', $lwRow['labor_work_steps'][1]['title']);
        $this->assertEquals('ГОСТ 12345', $lwRow['labor_work_steps'][1]['basis']);
        $this->assertEquals('Крепёж', $lwRow['labor_work_steps'][2]['title']);
        $this->assertNull($lwRow['labor_work_steps'][2]['basis']);

        // total_cost = 2.0 × 500 = 1000
        $this->assertEquals(1000.0, $lwRow['labor_work_total_cost']);
    }

    // ── 3. Non-labor_work rows have no labor_work_ keys ────────

    public function test_finalize_operation_row_has_no_labor_enrichment(): void
    {
        config(['smeta.evidence.operations_enabled' => true]);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E3-C-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $operation = \App\Models\Operation::create([
            'project_id' => $project->id,
            'name' => 'Распил тест',
            'category' => 'drilling',
            'unit' => 'шт',
            'user_id' => $user->id,
            'origin' => 'user',
            'price_per_unit' => 200,
            'currency' => 'RUB',
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::OPERATION,
            'evidence_subject_type' => 'operation',
            'evidence_subject_id' => $operation->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::OPERATION,
            'extracted_price' => 200.0,
            'currency' => 'RUB',
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 88883;
        $fakeRevision->number = 1;

        $this->mock(SnapshotService::class, function ($mock) use (&$captured, $fakeRevision) {
            $mock->shouldReceive('createSnapshot')
                ->once()
                ->withArgs(function ($proj, $userId, $extra) use (&$captured) {
                    $captured = $extra;
                    return true;
                })
                ->andReturn($fakeRevision);
        });

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize")
            ->assertStatus(200);

        $justifications = $captured['price_justifications'] ?? [];
        $opRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::OPERATION);

        $this->assertNotNull($opRow);
        $this->assertArrayNotHasKey('labor_work_hours', $opRow, 'Operation rows should not have labor_work_hours');
        $this->assertArrayNotHasKey('labor_work_steps', $opRow, 'Operation rows should not have labor_work_steps');
    }
}
