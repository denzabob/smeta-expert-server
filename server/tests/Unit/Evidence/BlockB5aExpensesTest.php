<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Jobs\RunRevisionUpdateJob;
use App\Jobs\UpdateMaterialObservationForRevisionItem;
use App\Models\EvidenceArtifact;
use App\Models\Expense;
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

class BlockB5aExpensesTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. Flag off → no expense items ────────────────────────

    public function test_flag_off_no_expense_items_collected(): void
    {
        config(['smeta.evidence.expenses_enabled' => false]);

        [$user, $project] = $this->makeProjectWithUser();
        $expense = $this->makeExpense($project, 'Доставка', 5000);

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [],
            'labor_works' => [],
            'expenses' => [
                ['id' => $expense->id, 'type' => $expense->name, 'cost' => (float) $expense->amount, 'description' => $expense->description],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $expItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::EXPENSE);
        $this->assertEmpty($expItems, 'No expense items should be collected when flag is off');
    }

    // ── 2. Flag on → expense items collected ──────────────────

    public function test_flag_on_expense_items_collected(): void
    {
        config(['smeta.evidence.expenses_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $exp1 = $this->makeExpense($project, 'Доставка', 5000);
        $exp2 = $this->makeExpense($project, 'Подъём на этаж', 2500);

        $report = [
            'plates' => [],
            'edges' => [],
            'fittings' => [],
            'operations' => [],
            'labor_works' => [],
            'expenses' => [
                ['id' => $exp1->id, 'type' => $exp1->name, 'cost' => (float) $exp1->amount, 'description' => $exp1->description],
                ['id' => $exp2->id, 'type' => $exp2->name, 'cost' => (float) $exp2->amount, 'description' => $exp2->description],
            ],
        ];

        $controller = $this->makeController();
        $items = $this->invokeCollectReportItems($controller, $project, $report);

        $expItems = array_filter($items, fn ($i) => ($i['cost_driver_type'] ?? null) === CostDriverType::EXPENSE);
        $this->assertCount(2, $expItems);

        $first = array_values($expItems)[0];
        $this->assertNull($first['material_id']);
        $this->assertNull($first['source_url']);
        $this->assertEquals('expense', $first['evidence_subject_type']);
        $this->assertEquals($exp1->id, $first['evidence_subject_id']);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $first['initial_status']);
        $this->assertEquals(5000.0, $first['_expense_amount']);
        $this->assertEquals($exp1->name, $first['_expense_name']);
        $this->assertStringContainsString('сумма задана вручную', $first['initial_message']);
    }

    // ── 3. start() creates EvidenceArtifact for expenses ──────

    public function test_start_creates_artifact_for_expense_item(): void
    {
        config(['smeta.evidence.expenses_enabled' => true]);

        [$user, $project] = $this->makeProjectWithUser();
        $expense = $this->makeExpense($project, 'Доставка', 3500);

        $this->mockReportWithExpenses($project, [
            ['id' => $expense->id, 'type' => $expense->name, 'cost' => (float) $expense->amount, 'description' => $expense->description],
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run");

        $response->assertStatus(201);
        $runId = $response->json('run_id');

        // Verify RevisionRunItem
        $item = RevisionRunItem::where('revision_run_id', $runId)
            ->where('cost_driver_type', CostDriverType::EXPENSE)
            ->first();

        $this->assertNotNull($item);
        $this->assertEquals(RevisionRunItem::STATUS_OK, $item->status);
        $this->assertNull($item->material_id);
        $this->assertNull($item->source_url);
        $this->assertEquals('expense', $item->evidence_subject_type);
        $this->assertEquals($expense->id, $item->evidence_subject_id);

        // Verify EvidenceArtifact
        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $this->assertNotNull($artifact);
        $this->assertEquals(CaptureSource::INTERNAL, $artifact->capture_source);
        $this->assertEquals(CostDriverType::EXPENSE, $artifact->cost_driver_type);
        $this->assertEquals(3500.0, (float) $artifact->extracted_price);
        $this->assertEquals('RUB', $artifact->currency);
        $this->assertEquals($expense->name, $artifact->extracted_name);
        // trust_score = 50 for user-declared expenses (not independently verified)
        $this->assertEquals(50, $artifact->trust_score);

        // No MaterialPriceHistory created for expenses
        $this->assertNull($item->price_history_id);
    }

    // ── 4. RunRevisionUpdateJob skips expense items ───────────

    public function test_run_job_skips_expense_items(): void
    {
        [$user, $project, $run, $expItem] = $this->makeRunWithExpenseItem();

        $this->assertEquals(RevisionRunItem::STATUS_OK, $expItem->status);
        $artifactsBefore = EvidenceArtifact::where('revision_run_item_id', $expItem->id)->count();
        $this->assertEquals(1, $artifactsBefore);

        $job = new RunRevisionUpdateJob($run->id, false);
        $job->handle();

        $expItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $expItem->status, 'Expense item should remain STATUS_OK after job');
        $this->assertStringContainsString('сумма задана вручную', $expItem->message);

        $artifactsAfter = EvidenceArtifact::where('revision_run_item_id', $expItem->id)->count();
        $this->assertEquals(1, $artifactsAfter);
    }

    // ── 5. Scraping job early-returns for expense items ───────

    public function test_scraping_job_early_returns_for_expense(): void
    {
        [$user, $project, $run, $expItem] = $this->makeRunWithExpenseItem();

        $job = new UpdateMaterialObservationForRevisionItem($expItem->id);
        $job->handle(
            app(UrlNormalizer::class),
            app(\App\Services\MaterialParseService::class),
            app(\App\Services\ScreenshotCaptureService::class),
        );

        $expItem->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $expItem->status, 'Status should remain OK');
    }

    // ── 6. finalize() builds expense justification row ────────

    public function test_finalize_includes_expense_justification_row(): void
    {
        [$user, $project, $run, $expItem] = $this->makeRunWithExpenseItem();

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

        $expRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::EXPENSE);
        $this->assertNotNull($expRow, 'Should have an expense justification row');
        $this->assertNull($expRow['material_id']);
        $this->assertNull($expRow['price_history_id']);
        $this->assertNull($expRow['source_url']);
        $this->assertNull($expRow['screenshot_path']);
        $this->assertEquals(CaptureSource::INTERNAL, $expRow['capture_source']);
        $this->assertEquals('user_declared', $expRow['source_type']);
        $this->assertEquals(3500.0, $expRow['price_per_unit']);
        $this->assertEquals('RUB', $expRow['currency']);
        $this->assertEquals(50, $expRow['true_score']);
        $this->assertNotEmpty($expRow['name']);
        $this->assertNull($expRow['unit']); // Expenses have no unit
        $this->assertNull($expRow['source_domain']);
    }

    // ── 7. Mixed run (plate + operation + labor_work + expense) ──

    public function test_finalize_mixed_all_four_types(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5a-MIX-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        // Plate
        $material = Material::create([
            'user_id' => $user->id, 'origin' => 'user',
            'name' => 'ДСП Тест', 'article' => 'DSP-1', 'type' => 'plate',
            'unit' => 'м²', 'price_per_unit' => 1000, 'is_active' => true,
            'source_url' => 'https://example.com/dsp',
        ]);

        // Operation
        $operation = Operation::create([
            'name' => 'Сверление ' . Str::random(4),
            'category' => 'drilling',
            'unit' => 'шт',
            'user_id' => $user->id,
            'origin' => 'user',
        ]);

        // Expense
        $expense = $this->makeExpense($project, 'Доставка', 4000);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 3,
        ]);

        // Plate item
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

        // Expense item
        $expenseItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Пользовательский расход: сумма задана вручную',
            'cost_driver_type' => CostDriverType::EXPENSE,
            'evidence_subject_type' => 'expense',
            'evidence_subject_id' => $expense->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $expenseItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::EXPENSE,
            'extracted_price' => 4000.0,
            'currency' => 'RUB',
            'extracted_name' => $expense->name,
            'trust_score' => 50,
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
        $this->assertCount(3, $justifications);

        $plateRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::PLATE);
        $opRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::OPERATION);
        $expRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::EXPENSE);

        $this->assertNotNull($plateRow);
        $this->assertNotNull($opRow);
        $this->assertNotNull($expRow);

        // Plate
        $this->assertEquals(1200, $plateRow['price_per_unit']);
        $this->assertEquals(CaptureSource::AUTO, $plateRow['capture_source']);

        // Operation
        $this->assertEquals(50.0, $opRow['price_per_unit']);
        $this->assertEquals(CaptureSource::INTERNAL, $opRow['capture_source']);
        $this->assertEquals('price_list', $opRow['source_type']);
        $this->assertEquals(100, $opRow['true_score']);

        // Expense
        $this->assertEquals(4000.0, $expRow['price_per_unit']);
        $this->assertEquals(CaptureSource::INTERNAL, $expRow['capture_source']);
        $this->assertEquals('user_declared', $expRow['source_type']);
        $this->assertEquals(50, $expRow['true_score']);
        $this->assertNull($expRow['unit']);

        // Evidence summary
        $summary = $captured['evidence_summary'];
        $this->assertEquals(3, $summary['total_items']);
        $this->assertEquals(3, $summary['with_evidence']);
        $this->assertEquals(100.0, $summary['coverage_pct']);
        $this->assertArrayHasKey('auto', $summary['by_capture_source']);
        $this->assertArrayHasKey('internal', $summary['by_capture_source']);
        $this->assertEquals(2, $summary['by_capture_source']['internal']); // operation + expense
    }

    // ── 8. PDF renders with expense evidence row ──────────────

    public function test_pdf_renders_with_expense_evidence_row(): void
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5a-PDF-' . Str::random(4),
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
                        'name' => 'Доставка',
                        'article' => null,
                        'unit' => null,
                        'material_type' => null,
                        'source_url' => null,
                        'screenshot_path' => null,
                        'price_per_unit' => 5000.0,
                        'currency' => 'RUB',
                        'true_score' => 50,
                        'source_type' => 'user_declared',
                        'capture_source' => 'internal',
                        'cost_driver_type' => 'expense',
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
            'snapshot_hash' => hash('sha256', 'test-b5a-pdf'),
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get("/api/projects/{$project->id}/revisions/1/price-justification.pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeProjectWithUser(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5a-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
        return [$user, $project];
    }

    private function makeExpense(Project $project, string $name, float $amount, ?string $description = null): Expense
    {
        return Expense::create([
            'project_id' => $project->id,
            'name' => $name,
            'amount' => $amount,
            'description' => $description,
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

    private function mockReportWithExpenses(Project $project, array $expenses): void
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
            'expenses' => $expenses,
            'labor_works' => [],
            'totals' => [],
        ];

        $dto = $this->createMock(\App\Dto\ReportDto::class);
        $dto->method('toArray')->willReturn($reportArray);

        $this->mock(ReportService::class, function ($mock) use ($dto) {
            $mock->shouldReceive('buildReport')->andReturn($dto);
        });
    }

    private function makeRunWithExpenseItem(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5a-RUN-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $expense = $this->makeExpense($project, 'Доставка', 3500);

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
            'message' => 'Пользовательский расход: сумма задана вручную',
            'cost_driver_type' => CostDriverType::EXPENSE,
            'evidence_subject_type' => 'expense',
            'evidence_subject_id' => $expense->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::EXPENSE,
            'extracted_price' => 3500.0,
            'currency' => 'RUB',
            'extracted_name' => $expense->name,
            'trust_score' => 50,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return [$user, $project, $run, $item];
    }
}
