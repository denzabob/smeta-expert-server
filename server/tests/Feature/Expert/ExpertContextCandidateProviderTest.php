<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertContextCandidateProvider;
use App\Services\Expert\ExpertMaterialIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExpertContextCandidateProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovers_structural_origins_and_ordered_batch_without_cross_project_materials(): void
    {
        $conversation = $this->conversation();
        $first = $this->material($conversation->project, '1.jpg');
        $second = $this->material($conversation->project, '2.jpg');
        $active = $this->material($conversation->project, 'Old expertise.pdf');
        $historical = $this->material($conversation->project, 'Prior.pdf');
        $metadataMatch = $this->material($conversation->project, 'Petrov contract.pdf');
        $foreign = $this->material($this->project($conversation->project->user_id), 'Petrov contract secret.pdf');
        $conversation->activeMaterials()->sync([$active->id]);
        $previous = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Earlier request',
            'metadata' => ['expert_context_snapshot' => [
                'version' => 3,
                'selected_sources' => [['material_id' => $active->public_id, 'role' => 'primary']],
            ]],
        ]);
        $previous->attachments()->create([
            'expert_project_material_id' => $historical->id,
            'position' => 0,
            'material_public_id_snapshot' => $historical->public_id,
            'original_name_snapshot' => $historical->original_name,
            'mime_type_snapshot' => $historical->mime_type,
            'size_snapshot' => 1,
        ]);

        $pool = app(ExpertContextCandidateProvider::class)->discover(
            $conversation,
            'Petrov contract',
            [$second->public_id, $first->public_id],
        );
        $byId = collect($pool->candidates)->keyBy('materialId');

        $this->assertSame([$second->public_id, $first->public_id], $pool->currentBatch->orderedMaterialIds);
        $this->assertSame(['current'], $byId[$first->public_id]->origins);
        $this->assertSame(1, $byId[$first->public_id]->currentAttachmentOrder);
        $this->assertSame(['last_primary', 'last_resolved', 'active', 'recent'], $byId[$active->public_id]->origins);
        $this->assertArrayNotHasKey('selected', $byId[$active->public_id]->descriptor());
        $this->assertSame(['historical'], $byId[$historical->public_id]->origins);
        $this->assertContains('project', $byId[$metadataMatch->public_id]->origins);
        $this->assertFalse($byId->has($foreign->public_id));
        $this->assertSame('basic', $byId[$first->public_id]->identity['state']);
        $this->assertNull($byId[$first->public_id]->identity['routing_text']);
        $this->assertDatabaseMissing('expert_material_identities', ['expert_project_material_id' => $foreign->id]);
    }

    public function test_focused_project_search_is_bounded_but_exhaustive_discovery_is_complete(): void
    {
        config()->set('expert.context_resolution.max_project_candidates', 2);
        $conversation = $this->conversation();
        $reports = [];
        foreach (range(1, 4) as $number) {
            $reports[] = $this->material($conversation->project, "report-{$number}.pdf");
        }
        $provider = app(ExpertContextCandidateProvider::class);
        $focused = $provider->discover($conversation, 'report', []);
        $this->assertCount(2, $focused->candidates);
        $this->assertTrue($focused->projectSearchTruncated);

        $exhaustive = $provider->discover($conversation, 'all project materials', [], coverageMode: 'exhaustive');
        $this->assertCount(4, $exhaustive->candidates);
        $this->assertFalse($exhaustive->projectSearchTruncated);

        $this->material($conversation->project, 'unrelated.pdf');
        $this->assertSame($focused->metadata()['hash'], $provider->discover($conversation, 'report', [])->metadata()['hash']);
    }

    public function test_enriched_identity_is_available_for_routing_without_selecting_the_candidate(): void
    {
        Storage::fake('local');
        $conversation = $this->conversation();
        $material = $this->material($conversation->project, 'scan001.pdf');
        Storage::disk('local')->put($material->storage_path, 'source bytes');
        app(ExpertMaterialIdentityService::class)->enrichFromText($material, 'Заключение эксперта Петрова', 'local_text', 'local-v1');

        $pool = app(ExpertContextCandidateProvider::class)->discover($conversation, 'scan001', []);
        $candidate = $pool->candidates[0];
        $this->assertSame('content_enriched', $candidate->identity['state']);
        $this->assertSame('Заключение эксперта Петрова', $candidate->identity['routing_text']);
        $this->assertArrayNotHasKey('selected', $candidate->descriptor());
    }

    public function test_identity_write_failure_returns_filename_fallback(): void
    {
        $conversation = $this->conversation();
        $material = $this->material($conversation->project, 'scan001.pdf');
        config()->set('expert.context.identity.version', "\xB1");

        $pool = app(ExpertContextCandidateProvider::class)->discover($conversation, 'scan001', []);

        $this->assertSame('failed', $pool->candidates[0]->identity['state']);
        $this->assertSame('scan001.pdf', $pool->candidates[0]->identity['display_name']);
        $this->assertSame('application/pdf', $pool->candidates[0]->identity['mime_type']);
        $this->assertSame(['project'], $pool->candidates[0]->origins);
        $this->assertDatabaseMissing('expert_material_identities', ['expert_project_material_id' => $material->id]);
    }

    public function test_discovery_keeps_file_backed_project_candidates_without_hashing_them(): void
    {
        Storage::fake('local');
        $conversation = $this->conversation();
        foreach (range(1, 25) as $number) {
            $material = $this->material($conversation->project, "report-{$number}.pdf");
            Storage::disk('local')->put($material->storage_path, str_repeat('binary', 1024));
        }

        $pool = app(ExpertContextCandidateProvider::class)->discover($conversation, 'report', []);

        $this->assertCount(25, $pool->candidates);
        $this->assertSame(25, $conversation->project->materials()->whereHas('identity')->count());
        $this->assertSame(0, $conversation->project->materials()->whereHas('identity',
            static fn ($query) => $query->whereNotNull('source_sha256'))->count());
        $this->assertGreaterThanOrEqual(0, $pool->identityLookupMs);
    }

    private function conversation(): ExpertConversation
    {
        $user = User::factory()->create();

        return $this->project($user->id)->conversations()->create(['title' => 'Candidates']);
    }

    private function project(int $userId): ExpertProject
    {
        return ExpertProject::create([
            'user_id' => $userId,
            'name' => 'Candidate project',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
    }

    private function material(ExpertProject $project, string $name): ExpertProjectMaterial
    {
        return $project->materials()->create([
            'uploaded_by' => $project->user_id,
            'original_name' => $name,
            'storage_path' => 'expert/candidates/'.$name,
            'mime_type' => str_ends_with($name, '.jpg') ? 'image/jpeg' : 'application/pdf',
            'extension' => pathinfo($name, PATHINFO_EXTENSION),
            'size' => 1,
            'category' => 'research',
            'status' => 'uploaded',
        ]);
    }
}
