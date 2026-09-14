<?php

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpertMaterialThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_thumbnail_is_private_owner_scoped_and_non_image_is_controlled(): void
    {
        [$user, $project] = $this->project();
        $other = User::factory()->create();
        $imageId = $this->uploadImage($user, $project, 'photo.jpg', 640, 480);
        $url = "/api/expert/materials/{$imageId}/thumbnail";

        $response = $this->actingAs($user, 'sanctum')->get($url)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));
        $this->assertNotEmpty($response->headers->get('etag'));
        $this->assertNotEmpty($response->headers->get('last-modified'));
        $material = $project->materials()->where('public_id', $imageId)->firstOrFail();
        $thumbnailPath = Storage::disk('local')->allFiles('expert/'.$project->public_id.'/thumbnails/'.$material->public_id)[0] ?? null;
        $this->assertNotNull($thumbnailPath);
        $dimensions = getimagesize(Storage::disk('local')->path($thumbnailPath));
        $this->assertLessThanOrEqual(320, $dimensions[0] ?? 0);
        $this->assertLessThanOrEqual(320, $dimensions[1] ?? 0);

        $this->actingAs($other, 'sanctum')->get($url)->assertForbidden();
        $documentId = $this->actingAs($user, 'sanctum')->post('/api/expert/projects/'.$project->public_id.'/materials', [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('public_id');
        $this->get("/api/expert/materials/{$documentId}/thumbnail")->assertNotFound();
        $this->get('/api/expert/materials/'.fake()->uuid().'/thumbnail')->assertNotFound();
    }

    public function test_thumbnail_rejects_bad_signature_and_dimension_or_pixel_limits_before_decode(): void
    {
        [$user, $project] = $this->project();
        $badPath = "expert/{$project->public_id}/materials/bad.jpg";
        $bad = ExpertProjectMaterial::create([
            'expert_project_id' => $project->id, 'uploaded_by' => $user->id, 'original_name' => 'bad.jpg',
            'storage_path' => $badPath, 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 8,
            'category' => 'image', 'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($badPath, 'not-image');
        $this->actingAs($user, 'sanctum')->get("/api/expert/materials/{$bad->public_id}/thumbnail")->assertStatus(422);

        config()->set('expert.material_thumbnails.max_source_bytes', 1);
        $sizeLimitedId = $this->uploadImage($user, $project, 'size-limited.jpg', 20, 20);
        $this->get("/api/expert/materials/{$sizeLimitedId}/thumbnail")->assertStatus(422);

        config()->set('expert.material_thumbnails.max_source_bytes', 15 * 1024 * 1024);
        config()->set('expert.material_thumbnails.max_width', 10);
        $limitedId = $this->uploadImage($user, $project, 'large.jpg', 20, 20);
        $this->get("/api/expert/materials/{$limitedId}/thumbnail")->assertStatus(422);
    }

    public function test_thumbnail_cache_reuses_etag_returns_304_and_changes_with_material_fingerprint(): void
    {
        [$user, $project] = $this->project();
        $id = $this->uploadImage($user, $project, 'cache.jpg', 800, 500);
        $url = "/api/expert/materials/{$id}/thumbnail";
        $first = $this->actingAs($user, 'sanctum')->get($url)->assertOk();
        $etag = $first->headers->get('etag');
        $lastModified = $first->headers->get('last-modified');
        $this->withHeaders(['If-None-Match' => $etag])->get($url)->assertStatus(304)->assertHeader('etag', $etag);
        $this->withHeaders(['If-Modified-Since' => $lastModified])->get($url)->assertStatus(304);

        $material = $project->materials()->where('public_id', $id)->firstOrFail();
        $material->forceFill(['updated_at' => now()->addMinute()])->save();
        $changed = $this->get($url)->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('etag'));
        $this->assertNotEmpty($changed->headers->get('last-modified'));
    }

    public function test_full_image_content_has_private_conditional_cache(): void
    {
        [$user, $project] = $this->project();
        $id = $this->uploadImage($user, $project, 'full.jpg', 100, 80);
        $url = "/api/expert/materials/{$id}/content";
        $first = $this->actingAs($user, 'sanctum')->get($url)->assertOk();
        $this->assertStringContainsString('private', (string) $first->headers->get('cache-control'));
        $this->withHeaders(['If-None-Match' => $first->headers->get('etag')])->get($url)->assertStatus(304);
    }

    public function test_delete_removes_private_thumbnail_derivative(): void
    {
        [$user, $project] = $this->project();
        $id = $this->uploadImage($user, $project, 'remove.jpg', 120, 90);
        $this->actingAs($user, 'sanctum')->get("/api/expert/materials/{$id}/thumbnail")->assertOk();
        $material = $project->materials()->where('public_id', $id)->firstOrFail();
        $directory = 'expert/'.$project->public_id.'/thumbnails/'.$material->public_id;
        $this->assertNotEmpty(Storage::disk('local')->allFiles($directory));
        $this->deleteJson("/api/expert/materials/{$id}")->assertNoContent();
        $this->assertSame([], Storage::disk('local')->allFiles($directory));
    }

    private function uploadImage(User $user, ExpertProject $project, string $name, int $width, int $height): string
    {
        return $this->actingAs($user, 'sanctum')->post('/api/expert/projects/'.$project->public_id.'/materials', [
            'file' => UploadedFile::fake()->image($name, $width, $height),
        ], ['Accept' => 'application/json'])->assertCreated()->json('public_id');
    }

    private function project(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create(['user_id' => $user->id, 'name' => 'Проект', 'domain' => 'other', 'work_type' => 'other']);

        return [$user, $project];
    }
}
