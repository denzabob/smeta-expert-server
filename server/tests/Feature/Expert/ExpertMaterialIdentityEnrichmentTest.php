<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertMaterialContextBuilder;
use App\Services\Expert\ExpertMaterialIdentityService;
use App\Services\Expert\ExpertMaterialIdentityTextBuilder;
use App\Services\Expert\ExpertPdfOcrCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExpertMaterialIdentityEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_routing_text_is_bounded_and_captures_head_middle_and_tail(): void
    {
        config()->set('expert.context.identity.routing_text_max_chars', 120);
        $source = 'НАЧАЛО '.str_repeat('а', 400).' СЕРЕДИНА '.str_repeat('б', 400).' КОНЕЦ';
        $routing = app(ExpertMaterialIdentityTextBuilder::class)->build($source);
        $this->assertLessThanOrEqual(120, mb_strlen($routing, 'UTF-8'));
        $this->assertStringContainsString('НАЧАЛО', $routing);
        $this->assertStringContainsString('СЕРЕДИНА', $routing);
        $this->assertStringContainsString('КОНЕЦ', $routing);
        $this->assertNotSame($source, $routing);
        config()->set('expert.context.identity.routing_text_max_chars', 1);
        $this->assertSame('Н', app(ExpertMaterialIdentityTextBuilder::class)->build($source));
    }

    public function test_provider_text_enriches_without_changing_material_and_lower_priority_local_text_cannot_replace_it(): void
    {
        $project = $this->project();
        $material = $project->materials()->create([
            'uploaded_by' => $project->user_id, 'original_name' => 'scan001.pdf',
            'storage_path' => 'expert/identity/scan001.pdf', 'mime_type' => 'application/pdf',
            'extension' => 'pdf', 'size' => 4, 'category' => 'document', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($material->storage_path, 'bytes');
        $candidate = new ExpertPdfOcrCandidate(
            $project->public_id, $material->public_id, 'scan001.pdf', 'application/pdf',
            'bytes', hash('sha256', 'bytes'), 1,
        );
        $service = app(ExpertMaterialIdentityService::class);
        $service->enrichPdfCandidate($candidate, 'Заключение эксперта Петрова');
        $identity = $service->get($material);
        $this->assertSame('content_enriched', $identity->state);
        $this->assertSame('provider_pdf_ocr', $identity->content_source);
        $this->assertSame('Заключение эксперта Петрова', $identity->routing_text);
        $this->assertNotNull($identity->content_fingerprint);
        $this->assertSame('bytes', Storage::disk('local')->get($material->storage_path));
        $this->assertSame('scan001.pdf', $material->fresh()->original_name);

        $service->enrichFromText($material, 'local text', 'local_text', 'local-v1');
        $this->assertSame('Заключение эксперта Петрова', $service->get($material)->routing_text);
    }

    public function test_repeated_enrichment_is_deterministic_and_assistant_text_is_not_an_input(): void
    {
        $project = $this->project();
        $material = $project->materials()->create([
            'uploaded_by' => $project->user_id, 'original_name' => 'note.txt',
            'storage_path' => 'expert/identity/note.txt', 'mime_type' => 'text/plain',
            'extension' => 'txt', 'size' => 4, 'category' => 'document', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($material->storage_path, 'text');
        $service = app(ExpertMaterialIdentityService::class);
        $first = $service->enrichFromText($material, 'real source', 'local_text', 'local-v1');
        $fingerprint = $first->content_fingerprint;
        $project->conversations()->create(['title' => 'History'])->messages()->create([
            'role' => 'assistant', 'content' => 'False assistant claim', 'metadata' => [],
        ]);
        $second = $service->enrichFromText($material, 'real source', 'local_text', 'local-v1');
        $this->assertSame($fingerprint, $second->content_fingerprint);
        $this->assertSame('real source', $second->routing_text);
    }

    public function test_failed_refresh_does_not_destroy_a_good_identity_or_throw_into_chat(): void
    {
        $project = $this->project();
        $material = $project->materials()->create([
            'uploaded_by' => $project->user_id, 'original_name' => 'note.txt',
            'storage_path' => 'expert/identity/failure.txt', 'mime_type' => 'text/plain',
            'extension' => 'txt', 'size' => 4, 'category' => 'document', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($material->storage_path, 'text');
        $service = app(ExpertMaterialIdentityService::class);
        $service->enrichFromText($material, 'verified source', 'local_text', 'local-v1');

        $broken = clone $material;
        $broken->original_name = "\xB1";
        $service->bestEffortEnrich($broken, 'unusable refresh', 'local_text', 'local-v2');

        $good = $service->get($material);
        $this->assertSame('content_enriched', $good->state);
        $this->assertSame('verified source', $good->routing_text);
    }

    public function test_identity_persistence_failure_does_not_block_chat_text_extraction(): void
    {
        $project = $this->project();
        $material = $project->materials()->create([
            'uploaded_by' => $project->user_id, 'original_name' => 'source.txt',
            'storage_path' => 'expert/identity/source.txt', 'mime_type' => 'text/plain',
            'extension' => 'txt', 'size' => 11, 'category' => 'document', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($material->storage_path, 'source text');
        config()->set('expert.context.identity.version', "\xB1");

        $context = app(ExpertMaterialContextBuilder::class)->buildForChat($project, [$material->public_id]);

        $this->assertSame('source text', $context->textMaterials[0]['text']);
    }

    private function project(): ExpertProject
    {
        return ExpertProject::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Identity project', 'domain' => 'other', 'work_type' => 'other',
        ]);
    }
}
