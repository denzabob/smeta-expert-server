<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertFinding;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Services\Expert\ExpertStorageUsageService;
use App\Services\Storage\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExpertStorageResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'expert.storage.disk' => 's1',
            'expert.storage.cache_disk' => 'local',
            'filesystems.disks.s1' => [
                'driver' => 's3',
                'key' => 'test-access-key',
                'secret' => 'test-secret',
                'region' => 'us-east-1',
                'bucket' => 'expert-test-bucket',
                'endpoint' => 'https://s1.test.invalid',
                'visibility' => 'private',
                'stream_reads' => true,
                'throw' => true,
                'report' => false,
            ],
        ]);
        Storage::fake('s1');
        Storage::fake('local');
    }

    public function test_reset_without_confirm_refuses_and_preserves_database_and_files(): void
    {
        [$user, $project, $material] = $this->material('keep me');

        $this->artisan('expert:storage-reset')
            ->expectsOutputToContain('REFUSING_DESTRUCTIVE_OPERATION')
            ->assertExitCode(1);

        $this->assertDatabaseHas('expert_project_materials', ['id' => $material->id]);
        Storage::disk('s1')->assertExists($material->storage_path);
        $this->assertSame('keep me', $user->fresh()->name);
        $this->assertDatabaseHas('expert_projects', ['id' => $project->id]);
    }

    public function test_confirmed_reset_removes_only_expert_files_and_recalculates_usage(): void
    {
        [$user, $project, $material] = $this->material('disposable');
        $conversation = ExpertConversation::query()->create([
            'expert_project_id' => $project->id,
            'title' => 'Keep conversation',
        ]);
        $message = ExpertMessage::query()->create([
            'expert_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Question with an attachment',
        ]);
        $message->attachments()->create([
            'expert_project_material_id' => $material->id,
            'position' => 0,
            'material_public_id_snapshot' => $material->public_id,
            'original_name_snapshot' => $material->original_name,
            'mime_type_snapshot' => $material->mime_type,
            'size_snapshot' => $material->size,
        ]);
        $finding = ExpertFinding::query()->create([
            'expert_project_id' => $project->id,
            'type' => 'observation',
            'title' => 'Keep finding',
            'status' => 'expert_confirmed',
            'created_by' => $user->id,
        ]);
        $finding->materials()->attach($material->id);

        DB::table('expert_storage_migrations')->insert([
            'material_id' => $material->id,
            'source_disk' => 'local',
            'source_key' => "expert/{$project->public_id}/materials/source.txt",
            'target_disk' => 's1',
            'target_key' => $material->storage_path,
            'source_size' => 10,
            'target_size' => 10,
            'status' => 'completed',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('storage_upload_reservations')->insert([
            'reservation_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'module' => 'expert',
            'requested_bytes' => 3,
            'status' => 'reserved',
            'expires_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        DB::table('storage_usages')->where('user_id', $user->id)->update(['reserved_bytes' => 3]);

        $localOrphan = "expert/{$project->public_id}/materials/orphan.txt";
        $localThumbnail = "expert/{$project->public_id}/thumbnails/{$material->public_id}/thumbnail.jpg";
        Storage::disk('local')->put($localOrphan, 'orphan');
        Storage::disk('local')->put($localThumbnail, 'thumbnail-cache');
        Storage::disk('local')->put('expert/health-checks/keep.txt', 'temporary health fixture');
        Storage::disk('s1')->put('expert/health-checks/keep.txt', 'temporary health fixture');
        Storage::disk('local')->put('chat-attachments/keep.txt', 'other module');

        $this->artisan('expert:storage-reset --confirm')
            ->expectsOutputToContain('DB objects removed:')
            ->expectsOutputToContain('Errors: 0')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('expert_project_materials', ['id' => $material->id]);
        $this->assertDatabaseMissing('expert_storage_migrations', ['material_id' => $material->id]);
        $this->assertDatabaseMissing('storage_upload_reservations', ['user_id' => $user->id, 'status' => 'reserved']);
        $this->assertDatabaseCount('expert_message_materials', 0);
        $this->assertDatabaseHas('expert_projects', ['id' => $project->id]);
        $this->assertDatabaseHas('expert_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('expert_messages', ['id' => $message->id]);
        $this->assertDatabaseHas('expert_findings', ['id' => $finding->id]);
        $this->assertDatabaseMissing('expert_finding_material', ['expert_project_material_id' => $material->id]);

        $snapshot = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(0, $snapshot->usedBytes);
        $this->assertSame(0, $snapshot->reservedBytes);
        $this->assertSame(0, $snapshot->materialsCount);

        Storage::disk('s1')->assertMissing($material->storage_path);
        Storage::disk('local')->assertMissing($localOrphan);
        Storage::disk('local')->assertMissing($localThumbnail);
        Storage::disk('local')->assertExists('expert/health-checks/keep.txt');
        Storage::disk('s1')->assertExists('expert/health-checks/keep.txt');
        Storage::disk('local')->assertExists('chat-attachments/keep.txt');
    }

    public function test_reset_preserves_a_physical_file_still_referenced_by_smeta(): void
    {
        [$user, , $material] = $this->material('shared evidence');
        $fileId = (int) DB::table('storage_files')->where('disk', 's1')->where('path', $material->storage_path)->value('id');
        $record = EvidenceRecord::query()->create(['uuid' => (string) Str::uuid(), 'cost_component' => 'material',
            'source_type' => 'manual', 'capture_method' => 'upload', 'created_by' => $user->id]);
        $asset = GenericEvidenceAsset::query()->create(['uuid' => (string) Str::uuid(), 'evidence_record_id' => $record->id,
            'asset_type' => 'document', 'storage_disk' => 's1', 'file_path' => $material->storage_path,
            'file_size' => strlen('shared evidence'), 'mime_type' => 'text/plain']);

        $this->artisan('expert:storage-reset', ['--confirm' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('expert_project_materials', ['id' => $material->id]);
        $this->assertDatabaseMissing('storage_file_links', ['storage_file_id' => $fileId, 'module' => 'expert']);
        $this->assertDatabaseHas('storage_file_links', ['storage_file_id' => $fileId, 'module' => 'smeta']);
        $this->assertDatabaseHas('generic_evidence_assets', ['id' => $asset->id, 'file_path' => $material->storage_path]);
        $this->assertDatabaseHas('storage_files', ['id' => $fileId, 'status' => 'active']);
        Storage::disk('s1')->assertExists($material->storage_path);
        $this->assertSame(strlen('shared evidence'), app(StorageUsageService::class)->getUserUsage((int) $user->id)['used_bytes']);
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage((int) $user->id)['reserved_bytes']);
    }

    private function material(string $contents): array
    {
        $user = User::factory()->create(['name' => 'keep me']);
        $project = ExpertProject::query()->create([
            'user_id' => $user->id,
            'name' => 'Disposable project file',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $key = "expert/{$project->public_id}/materials/".Str::uuid().'.txt';
        Storage::disk('s1')->put($key, $contents);
        $material = ExpertProjectMaterial::query()->create([
            'expert_project_id' => $project->id,
            'uploaded_by' => $user->id,
            'original_name' => 'fixture.txt',
            'storage_disk' => 's1',
            'storage_path' => $key,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen($contents),
            'category' => 'document',
            'status' => 'uploaded',
        ]);
        app(StorageUsageService::class)->register((int) $user->id, 'expert', [
            'disk' => 's1', 'path' => $key, 'purpose' => 'material', 'size_bytes' => strlen($contents),
            'mime_type' => 'text/plain', 'original_filename' => 'fixture.txt',
        ], [['source_type' => $material->getTable(), 'source_id' => $material->id]]);

        return [$user, $project, $material];
    }
}
