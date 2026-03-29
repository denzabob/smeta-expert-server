<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\User;
use App\Services\EstimateEvidencePdfBuilder;
use App\Services\EvidenceRunFinalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG4EvidencePdfTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Endpoint – returns PDF for finalized run
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_endpoint_returns_pdf_for_finalized_run(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeFinalizedRun($project, $user);

        $response = $this->actingAs($user)->get(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/pdf"
        );

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('evidence_', $response->headers->get('content-disposition'));
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Endpoint – rejects non-finalized run
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_endpoint_rejects_non_finalized_run(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS]);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/pdf"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Endpoint – 404 for missing run
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_endpoint_returns_404_for_missing_run(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs/99999/pdf"
        );

        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Endpoint – 404 when feature gate is off
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_endpoint_returns_404_when_gate_off(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeFinalizedRun($project, $user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/pdf"
        );

        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. Builder – summary keys from snapshot
    // ──────────────────────────────────────────────────────────────

    public function test_builder_produces_correct_summary_from_snapshot(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $this->makeSampleSnapshot(),
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $data = $builder->build($run, $project);

        $this->assertArrayHasKey('cover', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('exceptions', $data);
        $this->assertArrayHasKey('appendix', $data);

        // Summary
        $this->assertEquals(3, $data['summary']['total_items']);
        $this->assertEquals(2, $data['summary']['resolved']);
        $this->assertEquals(1, $data['summary']['skipped']);
        $this->assertEquals(0, $data['summary']['failed']);

        // Cover
        $this->assertEquals($project->number, $data['cover']['project_number']);
        $this->assertEquals($run->uuid, $data['cover']['run_uuid']);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Builder – detail sections per item
    // ──────────────────────────────────────────────────────────────

    public function test_builder_produces_detail_sections_per_item(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $this->makeSampleSnapshot(),
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $data = $builder->build($run, $project);

        $this->assertCount(3, $data['items']);

        // First item should have joined record data
        $first = $data['items'][0];
        $this->assertEquals('plate', $first['cost_component']);
        $this->assertEquals('resolved', $first['status']);
        $this->assertEquals('1200.00', $first['observed_price']);
        $this->assertEquals('https://store.example.com/plate-1', $first['source_url']);
        $this->assertEquals('chrome_extension', $first['capture_method']);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Builder – exceptions section
    // ──────────────────────────────────────────────────────────────

    public function test_builder_produces_exceptions_from_snapshot(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $this->makeSampleSnapshot(),
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $data = $builder->build($run, $project);

        $this->assertCount(1, $data['exceptions']);
        $this->assertEquals('skipped', $data['exceptions'][0]['status']);
        $this->assertEquals('fitting', $data['exceptions'][0]['cost_component']);
        $this->assertNotNull($data['exceptions'][0]['diagnostics']);
    }

    // ──────────────────────────────────────────────────────────────
    // 8. Builder – image asset vs non-image label
    // ──────────────────────────────────────────────────────────────

    public function test_builder_separates_image_and_non_image_assets(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $snapshot = $this->makeSampleSnapshot();
        // Add a non-image asset to record 101
        $snapshot['evidence_records'][0]['assets'][] = [
            'uuid' => 'doc-asset-uuid',
            'asset_type' => 'document',
            'file_path' => 'docs/invoice.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => 'abc123',
        ];

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $snapshot,
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $data = $builder->build($run, $project);

        $first = $data['items'][0]; // linked to record 101
        $this->assertNotNull($first['image_asset']);
        $this->assertEquals('image/jpeg', $first['image_asset']['mime_type']);
        $this->assertCount(1, $first['non_image_assets']);
        $this->assertEquals('application/pdf', $first['non_image_assets'][0]['mime_type']);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. Builder – appendix per record
    // ──────────────────────────────────────────────────────────────

    public function test_builder_produces_appendix_per_record(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $this->makeSampleSnapshot(),
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $data = $builder->build($run, $project);

        $this->assertCount(2, $data['appendix']);
        $this->assertEquals(101, $data['appendix'][0]['record_id']);
        $this->assertEquals('chrome_capture', $data['appendix'][0]['source_type']);
        $this->assertNotEmpty($data['appendix'][0]['assets']);
    }

    // ──────────────────────────────────────────────────────────────
    // 10. Snapshot enrichment – records include new fields
    // ──────────────────────────────────────────────────────────────

    public function test_snapshot_enrichment_includes_new_record_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);

        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => 'https://example.com/enriched',
            'source_domain'       => 'example.com',
            'observed_price'      => 999.00,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'extracted_name'      => 'Enriched Material',
            'extracted_article'   => 'ART-ENR',
            'metadata_json'       => ['capture_mode' => 'template'],
            'trust_score'         => 75,
            'created_by'          => $user->id,
        ]);

        $asset = GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test/enriched.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 12345,
            'sha256'             => hash('sha256', 'test-enrichment'),
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_run_id'    => $run->id,
            'cost_component'     => CostComponent::PLATE,
            'label'              => 'Enrichment Test',
            'status'             => EvidenceItemStatus::RESOLVED,
            'resolution_type'    => ResolutionType::CHROME,
            'subject_type'       => 'test',
            'subject_id'         => 1,
            'evidence_record_id' => $record->id,
            'source_url'         => 'https://example.com/enriched',
            'effective_value'    => 999.00,
            'currency'           => 'RUB',
        ]);

        // Force all items terminal so finalization succeeds
        $run->update(['status' => EvidenceRunStatus::READY]);

        $finalizer = app(EvidenceRunFinalizer::class);
        $run = $finalizer->finalize($run);

        $snapshot = $run->snapshot_json;
        $this->assertNotEmpty($snapshot['evidence_records']);

        $snapshotRecord = $snapshot['evidence_records'][0];
        // New enriched fields
        $this->assertEquals('chrome_extension', $snapshotRecord['capture_method']);
        $this->assertEquals('https://example.com/enriched', $snapshotRecord['source_url']);
        $this->assertEquals('example.com', $snapshotRecord['source_domain']);
        $this->assertNotNull($snapshotRecord['observed_at']);
        $this->assertNotNull($snapshotRecord['created_at']);
        $this->assertEquals($user->id, $snapshotRecord['created_by']);
        $this->assertEquals('ART-ENR', $snapshotRecord['extracted_article']);
        $this->assertNotNull($snapshotRecord['metadata_json']);
        $this->assertCount(1, $snapshotRecord['assets']);
        $this->assertEquals('screenshot', $snapshotRecord['assets'][0]['asset_type']);
        $this->assertEquals('image/jpeg', $snapshotRecord['assets'][0]['mime_type']);
        $this->assertNotEmpty($snapshotRecord['assets'][0]['sha256']);
    }

    // ──────────────────────────────────────────────────────────────
    // 11. Backward compat – legacy price-justification route works
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_price_justification_route_still_accessible(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        // Attempt to hit legacy route – expect 404 because no revision exists, not a route error
        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/revisions/1/price-justification.pdf"
        );

        // 404 = revision not found (route is active), not 500/405
        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────
    // 12. Endpoint – rejects run with empty snapshot
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_endpoint_rejects_run_with_empty_snapshot(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => null,
        ]);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/pdf"
        );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 13. Rendered HTML contains expected sections
    // ──────────────────────────────────────────────────────────────

    public function test_rendered_html_contains_expected_sections(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $run->update([
            'status' => EvidenceRunStatus::FINALIZED,
            'finalized_at' => now(),
            'snapshot_json' => $this->makeSampleSnapshot(),
        ]);

        $builder = app(EstimateEvidencePdfBuilder::class);
        $viewData = $builder->build($run, $project);

        $html = view('reports.evidence_run', $viewData)->render();

        // Cover
        $this->assertStringContainsString($project->number, $html);
        $this->assertStringContainsString($run->uuid, $html);

        // Summary
        $this->assertStringContainsString('Сводка покрытия', $html);
        $this->assertStringContainsString('Подтверждено', $html);

        // Detail items
        $this->assertStringContainsString('ЛДСП Белый 16мм', $html);
        $this->assertStringContainsString('store.example.com', $html);

        // Exceptions
        $this->assertStringContainsString('Исключения', $html);
        $this->assertStringContainsString('Петля накладная', $html);

        // Appendix
        $this->assertStringContainsString('Техническое приложение', $html);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'             => $user->id,
            'number'              => 'PRJ-G4-' . Str::random(4),
            'expert_name'         => 'Test Expert G4',
            'address'             => 'Test Address G4',
            'waste_coefficient'   => 1.0,
            'repair_coefficient'  => 1.0,
        ]);
    }

    private function makeRun(Project $project, User $user): EstimateEvidenceRun
    {
        return EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::PENDING,
            'total_items'     => 0,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);
    }

    private function makeFinalizedRun(Project $project, User $user): EstimateEvidenceRun
    {
        $run = $this->makeRun($project, $user);
        $run->update([
            'status'        => EvidenceRunStatus::FINALIZED,
            'finalized_at'  => now(),
            'snapshot_json'  => $this->makeSampleSnapshot(),
        ]);

        return $run;
    }

    private function makeSampleSnapshot(): array
    {
        return [
            'evidence_coverage_summary' => [
                'total_items' => 3,
                'by_status'   => ['resolved' => 2, 'skipped' => 1],
                'by_component' => ['plate' => 1, 'edge' => 1, 'fitting' => 1],
            ],
            'evidence_items' => [
                [
                    'uuid'               => 'item-uuid-1',
                    'cost_component'     => 'plate',
                    'label'              => 'ЛДСП Белый 16мм',
                    'status'             => 'resolved',
                    'resolution_type'    => 'chrome',
                    'subject_type'       => 'test',
                    'subject_id'         => 1,
                    'evidence_record_id' => 101,
                    'source_url'         => null,
                    'effective_value'    => '1200.00',
                    'currency'           => 'RUB',
                ],
                [
                    'uuid'               => 'item-uuid-2',
                    'cost_component'     => 'edge',
                    'label'              => 'Кромка ПВХ 2мм',
                    'status'             => 'resolved',
                    'resolution_type'    => 'manual',
                    'subject_type'       => 'test',
                    'subject_id'         => 2,
                    'evidence_record_id' => 102,
                    'source_url'         => null,
                    'effective_value'    => '85.00',
                    'currency'           => 'RUB',
                ],
                [
                    'uuid'               => 'item-uuid-3',
                    'cost_component'     => 'fitting',
                    'label'              => 'Петля накладная',
                    'status'             => 'skipped',
                    'resolution_type'    => 'skipped',
                    'subject_type'       => 'test',
                    'subject_id'         => 3,
                    'evidence_record_id' => null,
                    'source_url'         => null,
                    'effective_value'    => null,
                    'currency'           => null,
                ],
            ],
            'evidence_records' => [
                [
                    'id'                  => 101,
                    'uuid'                => 'rec-uuid-101',
                    'cost_component'      => 'plate',
                    'source_type'         => 'chrome_capture',
                    'capture_method'      => 'chrome_extension',
                    'observed_price'      => '1200.00',
                    'currency'            => 'RUB',
                    'extracted_name'      => 'ЛДСП Белый 16мм',
                    'extracted_article'   => 'ART-001',
                    'source_url'          => 'https://store.example.com/plate-1',
                    'source_domain'       => 'store.example.com',
                    'observed_at'         => '2026-03-28T12:00:00+00:00',
                    'verification_status' => 'pending',
                    'trust_score'         => 60,
                    'metadata_json'       => ['capture_mode' => 'template'],
                    'created_at'          => '2026-03-28T12:00:00+00:00',
                    'created_by'          => 1,
                    'assets'              => [
                        [
                            'uuid'       => 'asset-uuid-1',
                            'asset_type' => 'screenshot',
                            'file_path'  => 'screenshots/chrome/generic/2026/03/test.jpg',
                            'mime_type'  => 'image/jpeg',
                            'sha256'     => 'abcdef1234567890',
                        ],
                    ],
                ],
                [
                    'id'                  => 102,
                    'uuid'                => 'rec-uuid-102',
                    'cost_component'      => 'edge',
                    'source_type'         => 'chrome_capture',
                    'capture_method'      => 'chrome_extension',
                    'observed_price'      => '85.00',
                    'currency'            => 'RUB',
                    'extracted_name'      => 'Кромка ПВХ 2мм',
                    'extracted_article'   => null,
                    'source_url'          => 'https://store.example.com/edge-1',
                    'source_domain'       => 'store.example.com',
                    'observed_at'         => '2026-03-28T12:05:00+00:00',
                    'verification_status' => 'pending',
                    'trust_score'         => 60,
                    'metadata_json'       => null,
                    'created_at'          => '2026-03-28T12:05:00+00:00',
                    'created_by'          => 1,
                    'assets'              => [],
                ],
            ],
            'exceptions' => [
                [
                    'uuid'           => 'item-uuid-3',
                    'cost_component' => 'fitting',
                    'label'          => 'Петля накладная',
                    'status'         => 'skipped',
                    'diagnostics'    => ['skip_reason' => 'Нет поставщика'],
                ],
            ],
            'generation_meta' => [
                'finalized_at' => '2026-03-28T14:00:00+00:00',
                'version'      => 'generic_v1',
                'initiated_by' => 1,
            ],
        ];
    }
}
