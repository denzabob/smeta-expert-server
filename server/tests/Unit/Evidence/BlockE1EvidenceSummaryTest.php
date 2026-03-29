<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockE1EvidenceSummaryTest extends TestCase
{
    use DatabaseTransactions;

    // ── Finalize: snapshot includes evidence_summary ─────────────

    public function test_finalize_includes_evidence_summary_in_snapshot(): void
    {
        [$user, $project, $run] = $this->makeReadyRunWithMixedItems();

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
        $this->assertNotNull($captured);
        $this->assertArrayHasKey('evidence_summary', $captured);

        $summary = $captured['evidence_summary'];
        $this->assertEquals(3, $summary['total_items']);
        $this->assertEquals(2, $summary['with_evidence']);
        $this->assertEquals(66.7, $summary['coverage_pct']);
        $this->assertArrayHasKey('by_capture_source', $summary);
        $this->assertEquals(1, $summary['by_capture_source'][CaptureSource::AUTO]);
        $this->assertEquals(1, $summary['by_capture_source'][CaptureSource::MANUAL]);
    }

    // ── Finalize: per-item capture_source + cost_driver_type ─────

    public function test_finalize_per_item_includes_capture_source_and_cost_driver_type(): void
    {
        [$user, $project, $run] = $this->makeReadyRunWithMixedItems();

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

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $justifications = $captured['price_justifications'];
        $this->assertCount(3, $justifications);

        // All items should have cost_driver_type key
        foreach ($justifications as $j) {
            $this->assertArrayHasKey('capture_source', $j);
            $this->assertArrayHasKey('cost_driver_type', $j);
        }

        // First item has auto artifact, second has manual, third has none
        $this->assertEquals(CaptureSource::AUTO, $justifications[0]['capture_source']);
        $this->assertEquals(CaptureSource::MANUAL, $justifications[1]['capture_source']);
        $this->assertNull($justifications[2]['capture_source']);

        // All should have plate cost_driver_type
        $this->assertEquals(CostDriverType::PLATE, $justifications[0]['cost_driver_type']);
    }

    // ── Finalize: summary breakdown matches item counts ──────────

    public function test_evidence_summary_breakdown_matches_items(): void
    {
        [$user, $project, $run] = $this->makeReadyRunWithMixedItems();

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

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $summary = $captured['evidence_summary'];
        $bySource = $summary['by_capture_source'];

        // Sum of by_capture_source should equal with_evidence
        $this->assertEquals($summary['with_evidence'], array_sum($bySource));
        // Only auto and manual should be present (no chrome_ext in test data)
        $this->assertArrayNotHasKey(CaptureSource::CHROME_EXT, $bySource);
    }

    // ── PDF: renders without evidence_summary (backward compat) ──

    public function test_price_justification_pdf_renders_without_evidence_summary(): void
    {
        [$user, $project] = $this->makeProjectWithUser();

        $revision = ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => json_encode([
                'price_justifications' => [
                    [
                        'name' => 'Test Material',
                        'price_per_unit' => 1000,
                        'currency' => 'RUB',
                        'source_url' => 'https://example.com',
                    ],
                ],
                // Note: no evidence_summary key
            ]),
            'snapshot_hash' => hash('sha256', 'test-old'),
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get("/api/projects/{$project->id}/revisions/1/price-justification.pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── PDF: renders with evidence_summary ────────────────────────

    public function test_price_justification_pdf_renders_with_evidence_summary(): void
    {
        [$user, $project] = $this->makeProjectWithUser();

        $revision = ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => json_encode([
                'price_justifications' => [
                    [
                        'name' => 'ЛДСП Белая',
                        'price_per_unit' => 1200,
                        'currency' => 'RUB',
                        'source_url' => 'https://example.com/product',
                        'capture_source' => CaptureSource::AUTO,
                        'cost_driver_type' => CostDriverType::PLATE,
                    ],
                    [
                        'name' => 'Кромка ПВХ',
                        'price_per_unit' => 80,
                        'currency' => 'RUB',
                        'capture_source' => CaptureSource::MANUAL,
                        'cost_driver_type' => CostDriverType::EDGE,
                    ],
                ],
                'evidence_summary' => [
                    'total_items' => 2,
                    'with_evidence' => 2,
                    'coverage_pct' => 100.0,
                    'by_capture_source' => [
                        'auto' => 1,
                        'manual' => 1,
                    ],
                ],
            ]),
            'snapshot_hash' => hash('sha256', 'test-with-summary'),
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get("/api/projects/{$project->id}/revisions/1/price-justification.pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProjectWithUser(): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E1-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        return [$user, $project];
    }

    private function makeReadyRunWithMixedItems(): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E1-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Тест материал E1 ' . Str::random(4),
            'article' => 'E1-' . Str::random(4),
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/product',
            'is_active' => true,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 3,
            'ok_items' => 3,
            'failed_items' => 0,
        ]);

        // Item 1: has auto artifact
        $item1 = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/p1',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);
        EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item1->id,
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'extracted_price' => 1000,
            'currency' => 'RUB',
            'captured_at' => now(),
        ]);

        // Item 2: has manual artifact
        $item2 = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/p2',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);
        EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item2->id,
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'extracted_price' => 1200,
            'currency' => 'RUB',
            'captured_at' => now(),
        ]);

        // Item 3: no artifact (legacy item)
        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/p3',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        return [$user, $project, $run];
    }
}
