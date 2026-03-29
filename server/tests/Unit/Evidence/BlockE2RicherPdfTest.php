<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockE2RicherPdfTest extends TestCase
{
    use DatabaseTransactions;

    // ── Finalize: justification row includes source_domain ───────

    public function test_finalize_includes_source_domain_in_justification_row(): void
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-E2-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'ЛДСП Тест E2',
            'article' => 'E2-ART',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 900,
            'source_url' => 'https://shop.example.com/product/42',
            'is_active' => true,
        ]);

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
            'material_id' => $material->id,
            'source_url' => 'https://shop.example.com/product/42',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'source_url_raw' => 'https://shop.example.com/product/42',
            'source_domain' => 'shop.example.com',
            'extracted_price' => 900,
            'currency' => 'RUB',
            'captured_at' => now(),
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

        $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $row = $captured['price_justifications'][0];
        $this->assertArrayHasKey('source_domain', $row);
        $this->assertEquals('shop.example.com', $row['source_domain']);
    }

    // ── PDF: renders with all enriched E2 fields ─────────────────

    public function test_pdf_renders_with_all_enriched_fields(): void
    {
        [$user, $project] = $this->makeProjectWithUser();

        ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => json_encode([
                'price_justifications' => [
                    [
                        'name' => 'ЛДСП Белая 2800x2070',
                        'article' => 'LDSP-W-2800',
                        'unit' => 'м²',
                        'cost_driver_type' => CostDriverType::PLATE,
                        'capture_source' => CaptureSource::AUTO,
                        'source_url' => 'https://shop.example.com/product/42',
                        'source_domain' => 'shop.example.com',
                        'price_per_unit' => 1200,
                        'currency' => 'RUB',
                        'observed_at' => '2026-03-28T14:30:00+00:00',
                        'true_score' => 85,
                    ],
                    [
                        'name' => 'Кромка ПВХ 0.4мм',
                        'article' => 'EDGE-PVC-04',
                        'unit' => 'м.п.',
                        'cost_driver_type' => CostDriverType::EDGE,
                        'capture_source' => CaptureSource::MANUAL,
                        'source_url' => 'https://materials.test/edge/1',
                        'source_domain' => 'materials.test',
                        'price_per_unit' => 45,
                        'currency' => 'RUB',
                        'observed_at' => '2026-03-28T15:00:00+00:00',
                        'true_score' => 0,
                    ],
                ],
                'evidence_summary' => [
                    'total_items' => 2,
                    'with_evidence' => 2,
                    'coverage_pct' => 100.0,
                    'by_capture_source' => ['auto' => 1, 'manual' => 1],
                ],
            ]),
            'snapshot_hash' => hash('sha256', 'e2-enriched'),
            'locked_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get("/api/projects/{$project->id}/revisions/1/price-justification.pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── PDF: old snapshot without E2 fields renders cleanly ──────

    public function test_pdf_renders_with_old_snapshot_missing_new_fields(): void
    {
        [$user, $project] = $this->makeProjectWithUser();

        ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => json_encode([
                'price_justifications' => [
                    [
                        'name' => 'Legacy Material',
                        'price_per_unit' => 500,
                        'currency' => 'RUB',
                        'source_url' => 'https://old.example.com/product',
                        // No article, unit, cost_driver_type, source_domain, observed_at, true_score
                    ],
                ],
                // No evidence_summary
            ]),
            'snapshot_hash' => hash('sha256', 'e2-legacy'),
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
            'number' => 'PRJ-E2-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        return [$user, $project];
    }
}
