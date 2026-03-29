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
use App\Models\ProjectPosition;
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

class BlockB3aOperationsTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. Flag off → no operation items ───────────────────────

    public function test_flag_off_no_operation_items_collected(): void
    {
        config(['smeta.evidence.operations_enabled' => false]);

        [$user, $project] = $this->makeProjectWithUser();
        $operation = $this->makeOperation($user);

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [
                ['id' => $operation->id, 'name' => $operation->name, 'unit' => 'шт', 'cost_per_unit' => 50.0, 'quantity' => 10, 'total_cost' => 500.0],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $opItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::OPERATION);
        $this->assertEmpty($opItems, 'No operation items should be collected when flag is off');
    }

    // ── 2. Flag on → operation items collected ─────────────────

    public function test_flag_on_operation_items_collected(): void
    {
        config(['smeta.evidence.operations_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $op1 = $this->makeOperation($user, 'Сверление');
        $op2 = $this->makeOperation($user, 'Фрезеровка');

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [
                ['id' => $op1->id, 'name' => $op1->name, 'unit' => 'шт', 'cost_per_unit' => 50.0, 'quantity' => 10, 'total_cost' => 500.0],
                ['id' => $op2->id, 'name' => $op2->name, 'unit' => 'м', 'cost_per_unit' => 120.0, 'quantity' => 5, 'total_cost' => 600.0],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $opItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::OPERATION);
        $this->assertCount(2, $opItems);

        $first = array_values($opItems)[0];
        $this->assertNull($first['material_id']);
        $this->assertNull($first['source_url']);
        $this->assertEquals('operation', $first['evidence_subject_type']);
        $this->assertEquals($op1->id, $first['evidence_subject_id']);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $first['initial_status']);
        $this->assertEquals(50.0, $first['_operation_price']);
        $this->assertEquals($op1->name, $first['_operation_name']);
    }

    // ── 3. start() creates EvidenceArtifact for operations ─────

    public function test_start_creates_artifact_for_operation_item(): void
    {
        config(['smeta.evidence.operations_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $operation = $this->makeOperation($user, 'Раскрой');

        // Mock ReportService to return a report with one operation
        $this->mockReportWithOperations($project, [
            ['id' => $operation->id, 'name' => $operation->name, 'unit' => 'м²', 'cost_per_unit' => 200.0, 'quantity' => 3, 'total_cost' => 600.0],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run");

        $response->assertStatus(201);
        $runId = $response->json('run_id');

        // Verify RevisionRunItem
        $item = RevisionRunItem::where('revision_run_id', $runId)
            ->where('cost_driver_type', CostDriverType::OPERATION)
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $item->status);
        $this->assertNull($item->material_id);
        $this->assertNull($item->source_url);
        $this->assertEquals('operation', $item->evidence_subject_type);
        $this->assertEquals($operation->id, $item->evidence_subject_id);

        // Verify EvidenceArtifact
        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $this->assertNotNull($artifact);
        $this->assertEquals(CaptureSource::INTERNAL, $artifact->capture_source);
        $this->assertEquals(CostDriverType::OPERATION, $artifact->cost_driver_type);
        $this->assertEquals(200.0, (float) $artifact->extracted_price);
        $this->assertEquals('RUB', $artifact->currency);
        $this->assertEquals($operation->name, $artifact->extracted_name);
        $this->assertEquals(100, $artifact->trust_score);

        // No MaterialPriceHistory created for operations
        $this->assertNull($item->price_history_id);
    }

    // ── 4. RunRevisionUpdateJob skips operation items ──────────

    public function test_run_job_skips_operation_items(): void
    {
        [$user, $project, $run, $opItem] = $this->makeRunWithOperationItem();

        // Operation item starts as OK with a linked artifact
        $this->assertEquals(RevisionRunItem::STATUS_OK, $opItem->status);
        $artifactsBefore = EvidenceArtifact::where('revision_run_item_id', $opItem->id)->count();
        $this->assertEquals(1, $artifactsBefore);

        // Run the job — operation item must NOT be reset to PENDING
        $job = new RunRevisionUpdateJob($run->id, false);
        $job->handle();

        $opItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $opItem->status, 'Operation item should remain STATUS_OK after job');
        $this->assertStringContainsString('Внутренний источник', $opItem->message);

        // Artifact should still be there (not deleted or replaced)
        $artifactsAfter = EvidenceArtifact::where('revision_run_item_id', $opItem->id)->count();
        $this->assertEquals(1, $artifactsAfter);
    }

    // ── 5. Scraping job early-returns for operation items ──────

    public function test_scraping_job_early_returns_for_operation(): void
    {
        [$user, $project, $run, $opItem] = $this->makeRunWithOperationItem();

        // Even if somehow dispatched, the scraping job should early-return
        $job = new UpdateMaterialObservationForRevisionItem($opItem->id);
        // This should NOT throw or attempt material resolution
        $job->handle(
            app(UrlNormalizer::class),
            app(\App\Services\MaterialParseService::class),
            app(\App\Services\ScreenshotCaptureService::class),
        );

        $opItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $opItem->status, 'Status should remain OK');
    }

    // ── 6. finalize() builds operation justification row ───────

    public function test_finalize_includes_operation_justification_row(): void
    {
        [$user, $project, $run, $opItem] = $this->makeRunWithOperationItem();

        // Set run status so finalize can proceed
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

        $opRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::OPERATION);
        $this->assertNotNull($opRow, 'Should have an operation justification row');
        $this->assertNull($opRow['material_id']);
        $this->assertNull($opRow['price_history_id']);
        $this->assertNull($opRow['source_url']);
        $this->assertNull($opRow['screenshot_path']);
        $this->assertEquals(CaptureSource::INTERNAL, $opRow['capture_source']);
        $this->assertEquals('price_list', $opRow['source_type']);
        $this->assertEquals(200.0, $opRow['price_per_unit']);
        $this->assertEquals('RUB', $opRow['currency']);
        $this->assertEquals(100, $opRow['true_score']);
        $this->assertNotEmpty($opRow['name']);
    }

    // ── 7. Mixed run (plate + operation) finalizes correctly ───

    public function test_finalize_mixed_plate_and_operation(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B3a-MIX-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id, 'origin' => 'user',
            'name' => 'ДСП Тест', 'article' => 'DSP-1', 'type' => 'plate',
            'unit' => 'м²', 'price_per_unit' => 1000, 'is_active' => true,
            'source_url' => 'https://example.com/dsp',
        ]);

        $operation = $this->makeOperation($user, 'Сверление');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 2,
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

        // Operation item with internal artifact
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

        // Mock snapshot
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
        $this->assertCount(2, $justifications);

        $plateRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::PLATE);
        $opRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::OPERATION);

        $this->assertNotNull($plateRow);
        $this->assertNotNull($opRow);

        // Plate row reads from MaterialPriceHistory
        $this->assertEquals(1200, $plateRow['price_per_unit']);
        $this->assertEquals($material->id, $plateRow['material_id']);
        $this->assertEquals(CaptureSource::AUTO, $plateRow['capture_source']);

        // Operation row reads from artifact
        $this->assertNull($opRow['material_id']);
        $this->assertEquals(50.0, $opRow['price_per_unit']);
        $this->assertEquals(CaptureSource::INTERNAL, $opRow['capture_source']);
        $this->assertEquals('price_list', $opRow['source_type']);

        // Evidence summary counts both
        $summary = $captured['evidence_summary'];
        $this->assertEquals(2, $summary['total_items']);
        $this->assertEquals(2, $summary['with_evidence']);
        $this->assertEquals(100.0, $summary['coverage_pct']);
        $this->assertArrayHasKey('auto', $summary['by_capture_source']);
        $this->assertArrayHasKey('internal', $summary['by_capture_source']);
    }

    // ── 8. PDF renders with operation evidence ─────────────────

    public function test_pdf_renders_with_operation_evidence_row(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B3a-PDF-' . Str::random(4),
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
                        'name' => 'Сверление',
                        'article' => null,
                        'unit' => 'шт',
                        'material_type' => null,
                        'source_url' => null,
                        'screenshot_path' => null,
                        'price_per_unit' => 50.0,
                        'currency' => 'RUB',
                        'true_score' => 100,
                        'source_type' => 'price_list',
                        'capture_source' => 'internal',
                        'cost_driver_type' => 'operation',
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
            'snapshot_hash' => hash('sha256', 'test-b3a-pdf'),
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
            'number' => 'PRJ-B3a-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
        return [$user, $project];
    }

    private function makeOperation(User $user, string $name = 'Тестовая операция'): Operation
    {
        return Operation::create([
            'name' => $name . ' ' . Str::random(4),
            'category' => 'drilling',
            'unit' => 'шт',
            'user_id' => $user->id,
            'origin' => 'user',
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

    /**
     * Mock ReportService to return a report with zero materials and the given operations.
     */
    private function mockReportWithOperations(Project $project, array $operations): void
    {
        $reportArray = [
            'project' => ['number' => $project->number, 'expert_name' => 'E', 'address' => 'A'],
            'positions' => [],
            'plates' => [],
            'edges' => [],
            'facades' => [],
            'materials' => [],
            'operations' => $operations,
            'fittings' => [],
            'expenses' => [],
            'labor_works' => [],
            'totals' => [],
        ];

        $dto = $this->createMock(\App\Dto\ReportDto::class);
        $dto->method('toArray')->willReturn($reportArray);

        $this->mock(ReportService::class, function ($mock) use ($dto) {
            $mock->shouldReceive('buildReport')->andReturn($dto);
        });
    }

    /**
     * Create a run with a single operation item + artifact (pre-resolved).
     */
    private function makeRunWithOperationItem(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B3a-RUN-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $operation = $this->makeOperation($user, 'Раскрой');

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
            'message' => 'Внутренний источник: прайс-лист поставщика',
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
            'extracted_name' => $operation->name,
            'trust_score' => 100,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return [$user, $project, $run, $item];
    }
}
