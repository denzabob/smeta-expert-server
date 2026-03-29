<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Expense;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block F1 — expense document path inclusion in finalized snapshot rows
 * and guarded rendering in price_justification Blade.
 *
 * Covers:
 *   - finalize() includes expense_document_path/mime when document asset exists
 *   - finalize() leaves them null when no document asset exists
 *   - non-expense rows do not contain expense_document_* keys
 *   - Blade renders image preview for image/* mime
 *   - Blade renders compact label for application/pdf
 *   - Blade is stable for old snapshot rows without expense_document_* keys
 */
class BlockF1ExpenseDocumentInPdfTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. finalize() includes expense_document_path when document asset exists ──

    public function test_finalize_includes_expense_document_path_when_asset_exists(): void
    {
        config(['smeta.evidence.expenses_enabled' => true]);

        [$user, $project, $run, $item] = $this->makeRunWithExpenseItem();

        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();

        EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'document',
            'file_path' => 'evidence/documents/expenses/2026/03/receipt.pdf',
            'original_filename' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12345,
        ]);

        $run->update(['status' => RevisionRun::STATUS_READY]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 70001;
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
        $expRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::EXPENSE);

        $this->assertNotNull($expRow, 'Should have an expense justification row');
        $this->assertArrayHasKey('expense_document_path', $expRow);
        $this->assertArrayHasKey('expense_document_mime', $expRow);
        $this->assertEquals('evidence/documents/expenses/2026/03/receipt.pdf', $expRow['expense_document_path']);
        $this->assertEquals('application/pdf', $expRow['expense_document_mime']);
    }

    // ── 2. finalize() leaves expense_document_path null when no document asset ──

    public function test_finalize_expense_document_path_null_when_no_asset(): void
    {
        config(['smeta.evidence.expenses_enabled' => true]);

        [$user, $project, $run, $item] = $this->makeRunWithExpenseItem();
        $run->update(['status' => RevisionRun::STATUS_READY]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 70002;
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
        $expRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::EXPENSE);

        $this->assertNotNull($expRow);
        $this->assertArrayHasKey('expense_document_path', $expRow);
        $this->assertNull($expRow['expense_document_path']);
        $this->assertNull($expRow['expense_document_mime']);
    }

    // ── 3. Non-expense rows do not contain expense_document_* keys ──

    public function test_finalize_non_expense_rows_have_no_expense_document_keys(): void
    {
        config(['smeta.evidence.expenses_enabled' => true]);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-F1-NE-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id, 'origin' => 'user',
            'name' => 'ДСП Тест', 'article' => 'DSP-F1', 'type' => 'plate',
            'unit' => 'м²', 'price_per_unit' => 1000, 'is_active' => true,
        ]);

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'material_id' => $material->id,
            'name' => 'Полка',
            'length' => 600, 'width' => 400, 'quantity' => 2,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/dsp',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $plateItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'trust_score' => 60,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 70003;
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
        $plateRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::PLATE);

        $this->assertNotNull($plateRow);
        $this->assertArrayNotHasKey('expense_document_path', $plateRow, 'Plate rows should not have expense_document_path');
        $this->assertArrayNotHasKey('expense_document_mime', $plateRow, 'Plate rows should not have expense_document_mime');
    }

    // ── 4. Blade renders image preview for expense with image document ──

    public function test_blade_renders_image_document_for_expense(): void
    {
        $imagePath = 'evidence/documents/expenses/2026/03/photo.jpg';
        $fullPath = storage_path('app/public/' . $imagePath);

        // Create the directory and a temporary file for file_exists() check
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, 'fake-image-content');

        try {
            $html = view('reports.price_justification', [
                'rows' => [[
                    'name' => 'Доставка',
                    'cost_driver_type' => 'expense',
                    'capture_source' => 'internal',
                    'price_per_unit' => 3500,
                    'currency' => 'RUB',
                    'true_score' => 75,
                    'expense_document_path' => $imagePath,
                    'expense_document_mime' => 'image/jpeg',
                ]],
            ])->render();

            $this->assertStringContainsString('Документ', $html);
            $this->assertStringContainsString('<img', $html);
            $this->assertStringContainsString('photo.jpg', $html);
        } finally {
            @unlink($fullPath);
        }
    }

    // ── 5. Blade renders compact label for PDF document ──

    public function test_blade_renders_label_for_pdf_document(): void
    {
        $html = view('reports.price_justification', [
            'rows' => [[
                'name' => 'Расход на логистику',
                'cost_driver_type' => 'expense',
                'capture_source' => 'internal',
                'price_per_unit' => 5000,
                'currency' => 'RUB',
                'true_score' => 75,
                'expense_document_path' => 'evidence/documents/expenses/2026/03/invoice.pdf',
                'expense_document_mime' => 'application/pdf',
            ]],
        ])->render();

        $this->assertStringContainsString('Документ', $html);
        $this->assertStringContainsString('Приложен', $html);
        $this->assertStringContainsString('invoice.pdf', $html);
        // Should NOT contain an img tag for the document section (PDF file, not image)
        $this->assertStringNotContainsString('alt="document"', $html);
    }

    // ── 6. Blade is stable for old snapshot rows without expense_document_* keys ──

    public function test_blade_stable_for_old_snapshot_row_without_expense_document_keys(): void
    {
        // Old snapshot row: expense type but no expense_document_path/mime keys at all
        $html = view('reports.price_justification', [
            'rows' => [[
                'name' => 'Старый расход',
                'cost_driver_type' => 'expense',
                'capture_source' => 'internal',
                'price_per_unit' => 2000,
                'currency' => 'RUB',
                'true_score' => 50,
                'screenshot_path' => null,
            ]],
        ])->render();

        // Should render without errors
        $this->assertStringContainsString('Старый расход', $html);
        $this->assertStringContainsString('Расход', $html); // type badge
        $this->assertStringNotContainsString('Документ', $html); // No document section
        $this->assertStringContainsString('Скриншот отсутствует', $html); // Fallback
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeRunWithExpenseItem(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-F1-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $expense = Expense::create([
            'project_id' => $project->id,
            'name' => 'Доставка F1',
            'amount' => 4500,
            'description' => null,
        ]);

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
            'extracted_price' => 4500.0,
            'currency' => 'RUB',
            'extracted_name' => $expense->name,
            'trust_score' => 50,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return [$user, $project, $run, $item];
    }
}
