<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Jobs\RunRevisionUpdateJob;
use App\Jobs\UpdateMaterialObservationForRevisionItem;
use App\Models\EvidenceArtifact;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectLaborWork;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Service\ReportService;
use App\Services\SnapshotService;
use App\Services\UrlNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockB4LaborWorkTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. Flag off → no labor_work items ──────────────────────

    public function test_flag_off_no_labor_items_collected(): void
    {
        config(['smeta.evidence.labor_work_enabled' => false]);

        [$user, $project] = $this->makeProjectWithUser();
        $lw = $this->makeLaborWork($project);

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [],
            'labor_works' => [
                ['id' => $lw->id, 'title' => $lw->title, 'hours' => 2.0, 'rate_per_hour' => 1125.0, 'cost' => 2250.0],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $lwItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::LABOR_WORK);
        $this->assertEmpty($lwItems, 'No labor_work items should be collected when flag is off');
    }

    // ── 2. Flag on → labor_work items collected ────────────────

    public function test_flag_on_labor_items_collected(): void
    {
        config(['smeta.evidence.labor_work_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $lw1 = $this->makeLaborWork($project, 'Монтаж модуля');
        $lw2 = $this->makeLaborWork($project, 'Установка фасадов');

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [],
            'labor_works' => [
                ['id' => $lw1->id, 'title' => $lw1->title, 'hours' => 2.0, 'rate_per_hour' => 1125.0, 'cost' => 2250.0],
                ['id' => $lw2->id, 'title' => $lw2->title, 'hours' => 3.0, 'rate_per_hour' => 900.0, 'cost' => 2700.0],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $lwItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::LABOR_WORK);
        $this->assertCount(2, $lwItems);

        $first = array_values($lwItems)[0];
        $this->assertNull($first['material_id']);
        $this->assertNull($first['source_url']);
        $this->assertEquals('project_labor_work', $first['evidence_subject_type']);
        $this->assertEquals($lw1->id, $first['evidence_subject_id']);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $first['initial_status']);
        $this->assertEquals(1125.0, $first['_labor_rate']);
        $this->assertEquals($lw1->title, $first['_labor_title']);
        $this->assertEquals('н/ч', $first['_labor_unit']);
    }

    // ── 3. start() creates EvidenceArtifact for labor_work ─────

    public function test_start_creates_artifact_for_labor_item(): void
    {
        config(['smeta.evidence.labor_work_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $lw = $this->makeLaborWork($project, 'Сборка каркаса');

        $this->mockReportWithLaborWorks($project, [
            ['id' => $lw->id, 'title' => $lw->title, 'hours' => 4.0, 'rate_per_hour' => 800.0, 'cost' => 3200.0],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run");

        $response->assertStatus(201);
        $runId = $response->json('run_id');

        // Verify RevisionRunItem
        $item = RevisionRunItem::where('revision_run_id', $runId)
            ->where('cost_driver_type', CostDriverType::LABOR_WORK)
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $item->status);
        $this->assertNull($item->material_id);
        $this->assertNull($item->source_url);
        $this->assertEquals('project_labor_work', $item->evidence_subject_type);
        $this->assertEquals($lw->id, $item->evidence_subject_id);

        // Verify EvidenceArtifact
        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $this->assertNotNull($artifact);
        $this->assertEquals(CaptureSource::INTERNAL, $artifact->capture_source);
        $this->assertEquals(CostDriverType::LABOR_WORK, $artifact->cost_driver_type);
        $this->assertEquals(800.0, (float) $artifact->extracted_price);
        $this->assertEquals('RUB', $artifact->currency);
        $this->assertEquals($lw->title, $artifact->extracted_name);
        $this->assertEquals(100, $artifact->trust_score);

        // No MaterialPriceHistory for labor_work
        $this->assertNull($item->price_history_id);
    }

    // ── 4. RunRevisionUpdateJob skips labor_work items ─────────

    public function test_run_job_skips_labor_items(): void
    {
        [$user, $project, $run, $lwItem] = $this->makeRunWithLaborItem();

        $this->assertEquals(RevisionRunItem::STATUS_OK, $lwItem->status);
        $artifactsBefore = EvidenceArtifact::where('revision_run_item_id', $lwItem->id)->count();
        $this->assertEquals(1, $artifactsBefore);

        $job = new RunRevisionUpdateJob($run->id, false);
        $job->handle();

        $lwItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $lwItem->status, 'Labor item should remain STATUS_OK after job');
        $this->assertStringContainsString('Внутренний источник', $lwItem->message);

        $artifactsAfter = EvidenceArtifact::where('revision_run_item_id', $lwItem->id)->count();
        $this->assertEquals(1, $artifactsAfter);
    }

    // ── 5. Scraping job early-returns for labor_work items ─────

    public function test_scraping_job_early_returns_for_labor(): void
    {
        [$user, $project, $run, $lwItem] = $this->makeRunWithLaborItem();

        $job = new UpdateMaterialObservationForRevisionItem($lwItem->id);
        $job->handle(
            app(UrlNormalizer::class),
            app(\App\Services\MaterialParseService::class),
            app(\App\Services\ScreenshotCaptureService::class),
        );

        $lwItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $lwItem->status, 'Status should remain OK');
    }

    // ── 6. finalize() builds labor_work justification row ──────

    public function test_finalize_includes_labor_justification_row(): void
    {
        [$user, $project, $run, $lwItem] = $this->makeRunWithLaborItem();

        $run->update(['status' => RevisionRun::STATUS_READY]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 99999;
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
        $this->assertNotEmpty($justifications);

        $lwRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::LABOR_WORK);
        $this->assertNotNull($lwRow, 'Should have a labor_work justification row');
        $this->assertNull($lwRow['material_id']);
        $this->assertNull($lwRow['price_history_id']);
        $this->assertNull($lwRow['source_url']);
        $this->assertNull($lwRow['screenshot_path']);
        $this->assertEquals(CaptureSource::INTERNAL, $lwRow['capture_source']);
        $this->assertEquals('rate_computation', $lwRow['source_type']);
        $this->assertEquals(800.0, $lwRow['price_per_unit']);
        $this->assertEquals('RUB', $lwRow['currency']);
        $this->assertEquals(100, $lwRow['true_score']);
        $this->assertEquals('н/ч', $lwRow['unit']);
        $this->assertNotEmpty($lwRow['name']);
    }

    // ── 7. Mixed run (plate + operation + labor) finalizes ─────

    public function test_finalize_mixed_plate_operation_labor(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B4-MIX-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id, 'origin' => 'user',
            'name' => 'ДСП Тест', 'article' => 'DSP-1', 'type' => 'plate',
            'unit' => 'м²', 'price_per_unit' => 1000, 'is_active' => true,
            'source_url' => 'https://example.com/dsp',
        ]);

        $operation = Operation::create([
            'name' => 'Сверление ' . Str::random(4),
            'category' => 'drilling',
            'unit' => 'шт',
            'user_id' => $user->id,
            'origin' => 'user',
        ]);

        $laborWork = $this->makeLaborWork($project, 'Монтаж');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 3,
        ]);

        // Plate item with MaterialPriceHistory
        $history = MaterialPriceHistory::create([
            'material_id' => $material->id, 'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 1200, 'currency' => 'RUB',
            'source_url' => 'https://example.com/dsp',
            'normalized_source_url' => 'https://example.com/dsp',
            'observed_at' => now(), 'source_type' => 'web',
            'is_verified' => true, 'true_score' => 85,
        ]);

        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/dsp',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
            'price_history_id' => $history->id,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => 1,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $plateItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'source_url_raw' => 'https://example.com/dsp',
            'captured_at' => now(),
        ]);

        // Operation item
        $opItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Внутренний источник: прайс-лист поставщика',
            'cost_driver_type' => CostDriverType::OPERATION,
            'evidence_subject_type' => 'operation',
            'evidence_subject_id' => $operation->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $opItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::OPERATION,
            'extracted_price' => 50.0,
            'currency' => 'RUB',
            'extracted_name' => $operation->name,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        // Labor work item
        $lwItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Внутренний источник: расчёт ставки нормо-часа',
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'evidence_subject_type' => 'project_labor_work',
            'evidence_subject_id' => $laborWork->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $lwItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::LABOR_WORK,
            'extracted_price' => 1125.0,
            'currency' => 'RUB',
            'extracted_name' => $laborWork->title,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 99999;
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

        $justifications = $captured['price_justifications'];
        $this->assertCount(3, $justifications);

        $plateRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::PLATE);
        $opRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::OPERATION);
        $lwRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::LABOR_WORK);

        $this->assertNotNull($plateRow);
        $this->assertNotNull($opRow);
        $this->assertNotNull($lwRow);

        // Plate row
        $this->assertEquals(1200, $plateRow['price_per_unit']);
        $this->assertEquals(CaptureSource::AUTO, $plateRow['capture_source']);

        // Operation row — stays price_list
        $this->assertEquals(50.0, $opRow['price_per_unit']);
        $this->assertEquals('price_list', $opRow['source_type']);
        $this->assertEquals(CaptureSource::INTERNAL, $opRow['capture_source']);

        // Labor work row — rate_computation
        $this->assertEquals(1125.0, $lwRow['price_per_unit']);
        $this->assertEquals('rate_computation', $lwRow['source_type']);
        $this->assertEquals(CaptureSource::INTERNAL, $lwRow['capture_source']);
        $this->assertEquals('н/ч', $lwRow['unit']);
        $this->assertNull($lwRow['material_id']);

        // Evidence summary counts all three
        $summary = $captured['evidence_summary'];
        $this->assertEquals(3, $summary['total_items']);
        $this->assertEquals(3, $summary['with_evidence']);
        $this->assertEquals(100.0, $summary['coverage_pct']);
        $this->assertArrayHasKey('auto', $summary['by_capture_source']);
        $this->assertArrayHasKey('internal', $summary['by_capture_source']);
        $this->assertEquals(2, $summary['by_capture_source']['internal']);
    }

    // ── 8. PDF renders with labor_work evidence ────────────────

    public function test_pdf_renders_with_labor_evidence_row(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B4-PDF-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => json_encode([
                'project' => ['number' => $project->number, 'expert_name' => 'Expert', 'address' => 'Addr'],
                'price_justifications' => [
                    [
                        'name' => 'Монтаж каркаса',
                        'article' => null,
                        'unit' => 'н/ч',
                        'material_type' => null,
                        'source_url' => null,
                        'screenshot_path' => null,
                        'price_per_unit' => 1125.0,
                        'currency' => 'RUB',
                        'true_score' => 100,
                        'source_type' => 'rate_computation',
                        'capture_source' => 'internal',
                        'cost_driver_type' => 'labor_work',
                        'source_domain' => null,
                        'observed_at' => now()->toIso8601String(),
                    ],
                ],
                'evidence_summary' => [
                    'total_items' => 1,
                    'with_evidence' => 1,
                    'coverage_pct' => 100.0,
                    'by_capture_source' => ['internal' => 1],
                ],
            ]),
            'snapshot_hash' => hash('sha256', 'test-b4-pdf'),
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get("/api/projects/{$project->id}/revisions/1/price-justification.pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function makeProjectWithUser(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B4-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
        return [$user, $project];
    }

    private function makeLaborWork(Project $project, string $title = 'Тестовая работа'): ProjectLaborWork
    {
        return ProjectLaborWork::create([
            'project_id' => $project->id,
            'title' => $title . ' ' . Str::random(4),
            'hours' => 2.0,
            'hours_source' => 'manual',
            'sort_order' => 0,
        ]);
    }

    private function makeController(): \App\Http\Controllers\Api\RevisionRunController
    {
        return app(\App\Http\Controllers\Api\RevisionRunController::class);
    }

    private function invokeCollectReportItems($controller, Project $project, array $report): array
    {
        $method = new \ReflectionMethod($controller, 'collectReportItems');
        $method->setAccessible(true);
        return $method->invoke($controller, $project, $report);
    }

    private function mockReportWithLaborWorks(Project $project, array $laborWorks): void
    {
        $reportArray = [
            'project' => ['number' => $project->number, 'expert_name' => 'E', 'address' => 'A'],
            'positions' => [],
            'plates' => [],
            'edges' => [],
            'facades' => [],
            'materials' => [],
            'operations' => [],
            'fittings' => [],
            'expenses' => [],
            'labor_works' => $laborWorks,
            'totals' => [],
        ];

        $dto = $this->createMock(\App\Dto\ReportDto::class);
        $dto->method('toArray')->willReturn($reportArray);

        $this->mock(ReportService::class, function ($mock) use ($dto) {
            $mock->shouldReceive('buildReport')->andReturn($dto);
        });
    }

    private function makeRunWithLaborItem(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B4-RUN-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $laborWork = $this->makeLaborWork($project, 'Сборка каркаса');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Внутренний источник: расчёт ставки нормо-часа',
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
            'extracted_price' => 800.0,
            'currency' => 'RUB',
            'extracted_name' => $laborWork->title,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return [$user, $project, $run, $item];
    }
}
