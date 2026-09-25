<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertMaterialIdentity;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertMaterialIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExpertMaterialIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_lazy_basic_identity_uses_filename_stem_and_no_provider_call(): void
    {
        $material = $this->material($this->project(), 'Заключение Иванова.pdf', 'PDF bytes');
        $this->assertDatabaseMissing('expert_material_identities', ['expert_project_material_id' => $material->id]);

        $identity = app(ExpertMaterialIdentityService::class)->ensureBasic($material);

        $this->assertSame('basic', $identity->state);
        $this->assertSame('filename', $identity->content_source);
        $this->assertSame(['Заключение Иванова.pdf', 'Заключение Иванова'], $identity->descriptor['aliases']);
        $this->assertSame(hash('sha256', 'PDF bytes'), $identity->source_sha256);
        $this->assertNull($identity->routing_text);
        $this->assertSame($identity->id, app(ExpertMaterialIdentityService::class)->ensureBasic($material)->id);
    }

    public function test_metadata_rename_keeps_enrichment_but_source_and_version_changes_make_it_stale(): void
    {
        $material = $this->material($this->project(), 'scan001.pdf', 'original bytes');
        $service = app(ExpertMaterialIdentityService::class);
        $enriched = $service->enrichFromText($material, 'Экспертное заключение', 'local_text', 'local-v1');
        $originalMetadataHash = $enriched->metadata_hash;
        $originalFingerprint = $enriched->content_fingerprint;

        $material->update(['original_name' => 'Заключение.pdf']);
        $renamed = $service->ensureBasic($material->fresh());
        $this->assertNotSame($originalMetadataHash, $renamed->metadata_hash);
        $this->assertSame('content_enriched', $renamed->state);
        $this->assertSame($originalFingerprint, $renamed->content_fingerprint);
        $this->assertSame(['Заключение.pdf', 'Заключение'], $renamed->descriptor['aliases']);

        Storage::disk('local')->put($material->storage_path, 'new bytes');
        $stale = $service->ensureBasic($material->fresh());
        $this->assertSame('stale', $stale->state);
        $this->assertNull($stale->toRoutingDescriptor($material)['routing_text']);
        $this->assertNull($stale->content_fingerprint);
        $service->enrichFromText($material->fresh(), 'Новое содержимое', 'local_text', 'local-v1');

        config()->set('expert.context.identity.version', 'v2');
        $versionStale = $service->ensureBasic($material->fresh());
        $this->assertSame('stale', $versionStale->state);
        $this->assertSame('v2', $versionStale->schema_version);
    }

    public function test_identical_bytes_remain_project_scoped_and_delete_cascades(): void
    {
        $first = $this->material($this->project(), 'A.pdf', 'same');
        $second = $this->material($this->project(), 'B.pdf', 'same');
        $foreign = $this->material($this->project(), 'C.pdf', 'same');
        $service = app(ExpertMaterialIdentityService::class);
        $a = $service->ensureBasic($first);
        $b = $service->ensureBasic($second);
        $c = $service->ensureBasic($foreign);
        $this->assertSame($a->source_sha256, $b->source_sha256);
        $this->assertSame($a->source_sha256, $c->source_sha256);
        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);

        $first->delete();
        $this->assertNull(ExpertMaterialIdentity::find($a->id));
        $this->assertNotNull(ExpertMaterialIdentity::find($b->id));
    }

    private function project(): ExpertProject
    {
        return ExpertProject::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Identity project', 'domain' => 'other', 'work_type' => 'other',
        ]);
    }

    private function material(ExpertProject $project, string $name, string $bytes): ExpertProjectMaterial
    {
        $material = $project->materials()->create([
            'uploaded_by' => $project->user_id,
            'original_name' => $name,
            'storage_path' => 'expert/identity/'.uniqid('', true).'.pdf',
            'mime_type' => 'application/pdf', 'extension' => 'pdf',
            'size' => strlen($bytes), 'category' => 'document', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($material->storage_path, $bytes);

        return $material;
    }
}
