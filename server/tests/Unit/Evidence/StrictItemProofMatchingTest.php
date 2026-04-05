<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Strict Item-to-Proof Matching tests.
 *
 * Ensures:
 *  - resolveItem rejects records with wrong cost_component
 *  - resolveItem rejects records with wrong source_url
 *  - resolveItem rejects records without proof asset
 *  - resolveItem rejects rejected records
 *  - resolveItem accepts valid matching records
 *  - candidates endpoint returns only matching records
 *  - candidates endpoint excludes wrong-component records
 *  - candidates endpoint excludes wrong-URL records
 *  - candidates endpoint excludes records without assets
 */
class StrictItemProofMatchingTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. resolveItem rejects record with wrong cost_component
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_rejects_wrong_cost_component(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        // Create a proof record for EDGE, not PLATE
        $record = $this->makeRecord($user, CostComponent::EDGE, 'https://example.com/product/plate', withAsset: true);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('does not match', $response->json('message'));
    }

    // ──────────────────────────────────────────────────────────────
    // 2. resolveItem rejects record with wrong source_url
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_rejects_wrong_source_url(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate-a',
        );

        $record = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate-b', withAsset: true);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. resolveItem rejects record without proof asset
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_rejects_record_without_asset(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        $record = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: false);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. resolveItem rejects rejected record
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_rejects_rejected_record(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        $record = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: true, status: 'rejected');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. resolveItem accepts valid matching record
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_accepts_valid_matching_record(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        $record = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: true);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertSame($record->id, $item->evidence_record_id);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. resolveItem accepts record when item has no source_url
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_accepts_record_when_item_has_no_url(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            null,  // no source_url on item
        );

        $record = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: true);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
                ['evidence_record_id' => $record->id],
            );

        // When item has no URL, any record with matching component + asset is valid
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. candidates endpoint returns only matching records
    // ──────────────────────────────────────────────────────────────

    public function test_candidates_returns_only_matching_records(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        // Valid candidate
        $validRecord = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: true);

        // Wrong component
        $this->makeRecord($user, CostComponent::EDGE, 'https://example.com/product/plate', withAsset: true);

        // Wrong URL
        $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/other', withAsset: true);

        // No asset
        $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: false);

        // Rejected
        $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/plate', withAsset: true, status: 'rejected');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/candidates",
            );

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($validRecord->id, $ids);
        $this->assertCount(1, $ids, 'Only the valid candidate should be returned');
    }

    // ──────────────────────────────────────────────────────────────
    // 8. candidates supports text search within strict set
    // ──────────────────────────────────────────────────────────────

    public function test_candidates_supports_text_search(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            'https://example.com/product/plate',
        );

        $matchingRecord = $this->makeRecord(
            $user,
            CostComponent::PLATE,
            'https://example.com/product/plate',
            withAsset: true,
            name: 'Kronospan 18mm White',
        );

        $otherRecord = $this->makeRecord(
            $user,
            CostComponent::PLATE,
            'https://example.com/product/plate',
            withAsset: true,
            name: 'Egger U961 Grey',
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/candidates?q=Kronospan",
            );

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($matchingRecord->id, $ids);
        $this->assertNotContains($otherRecord->id, $ids);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. candidates for item without URL returns all component matches
    // ──────────────────────────────────────────────────────────────

    public function test_candidates_for_item_without_url_returns_component_matches(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            CostComponent::PLATE,
            null,  // no source_url
        );

        $record1 = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/a', withAsset: true);
        $record2 = $this->makeRecord($user, CostComponent::PLATE, 'https://example.com/product/b', withAsset: true);
        $wrongComp = $this->makeRecord($user, CostComponent::EDGE, 'https://example.com/product/a', withAsset: true);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson(
                "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/candidates",
            );

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($record1->id, $ids);
        $this->assertContains($record2->id, $ids);
        $this->assertNotContains($wrongComp->id, $ids);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'number'      => 'PRJ-STRICT-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address'     => 'Test Address',
        ]);
    }

    private function makeRunWithItem(string $component, ?string $sourceUrl): array
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => $component,
            'label'           => 'Test Item ' . $component,
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $sourceUrl,
        ]);

        return [$user, $project, $run, $item];
    }

    private function makeRecord(
        User $user,
        string $component,
        string $sourceUrl,
        bool $withAsset = true,
        string $status = 'pending',
        string $name = 'Test Material',
    ): EvidenceRecord {
        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => $component,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => $status,
            'source_url'          => $sourceUrl,
            'source_domain'       => parse_url($sourceUrl, PHP_URL_HOST) ?: null,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'extracted_name'      => $name,
            'created_by'          => $user->id,
        ]);

        if ($withAsset) {
            GenericEvidenceAsset::create([
                'uuid'               => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type'         => 'screenshot',
                'file_path'          => 'screenshots/test/' . Str::random(8) . '.jpg',
                'original_filename'  => 'proof.jpg',
                'mime_type'          => 'image/jpeg',
                'file_size'          => 1024,
                'sha256'             => hash('sha256', Str::random(16)),
            ]);
        }

        return $record;
    }
}
