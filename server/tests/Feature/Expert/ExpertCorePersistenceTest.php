<?php

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Services\LLM\DTO\LLMChatResponse;
use App\Services\LLM\LLMRouter;
use App\Services\Expert\ExpertStorageCleanupService;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ExpertCorePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_project_ownership_and_core_crud(): void
    {
        $this->getJson('/api/expert/projects')->assertUnauthorized();
        $owner=User::factory()->create(); $other=User::factory()->create();
        $created=$this->actingAs($owner,'sanctum')->postJson('/api/expert/projects',[
            'name'=>'Экспертиза кухни','domain'=>'commodity','work_type'=>'pretrial_research',
            'research_questions'=>[['id'=>'q1','order'=>1,'text'=>'Какова стоимость?']],
            'initial_research_object'=>['name'=>'Кухонный гарнитур','type'=>'product'],
        ])->assertCreated()->assertJsonMissingPath('id')->assertJsonPath('research_questions.0.id','q1');
        $projectId=$created->json('public_id');
        $this->actingAs($other,'sanctum')->getJson("/api/expert/projects/{$projectId}")->assertForbidden();
        $this->actingAs($owner,'sanctum')->getJson('/api/expert/projects/'.Str::uuid())->assertNotFound();
        $this->actingAs($owner,'sanctum')->patchJson("/api/expert/projects/{$projectId}",['customer'=>'Заказчик'])->assertOk()->assertJsonPath('customer','Заказчик');
        $object=$this->postJson("/api/expert/projects/{$projectId}/research-objects",['name'=>'Фасад'])->assertCreated();
        $this->patchJson('/api/expert/research-objects/'.$object->json('public_id'),['sort_order'=>2])->assertOk()->assertJsonPath('sort_order',2);
        $this->getJson("/api/expert/projects/{$projectId}")->assertOk()->assertJsonCount(2,'research_objects');
    }

    public function test_expert_route_names_do_not_replace_existing_project_routes(): void
    {
        $this->assertSame('/api/projects', route('projects.index', [], false));
        $this->assertSame('/api/expert/projects', route('expert.projects.index', [], false));
    }

    public function test_conversations_are_sorted_by_latest_message_activity(): void
    {
        [$user, $project] = $this->project();
        $older = $project->conversations()->create(['title' => 'Старый чат']);
        $recent = $project->conversations()->create(['title' => 'Активный чат']);
        $older->forceFill(['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)])->saveQuietly();
        $recent->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->saveQuietly();
        $recent->messages()->create(['role' => 'user', 'content' => 'Последнее сообщение'])
            ->forceFill(['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()])->saveQuietly();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/expert/projects/{$project->public_id}/conversations")
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $recent->public_id)
            ->assertJsonPath('data.1.public_id', $older->public_id);

        $this->assertNotNull($response->json('data.0.last_message_at'));
    }

    public function test_conversation_messages_persist_and_client_cannot_forge_role(): void
    {
        [$user,$project]=$this->project();
        $router=Mockery::mock(LLMRouter::class);
        $router->shouldReceive('setUserId')->once()->with($user->id)->andReturnSelf();
        $router->shouldReceive('chat')->once()->andReturn(new LLMChatResponse('fake','fake-chat','Тестовый ответ',1));
        $this->app->instance(LLMRouter::class,$router);
        $conversation=$this->actingAs($user,'sanctum')->postJson("/api/expert/projects/{$project->public_id}/conversations",['title'=>'Общий анализ'])->assertCreated();
        $url='/api/expert/conversations/'.$conversation->json('public_id').'/messages';
        $this->postJson($url,['content'=>'Проверь документ','role'=>'assistant'],['X-Expert-Message-Id'=>(string) Str::uuid()])
            ->assertCreated()
            ->assertJsonPath('user_message.role','user')
            ->assertJsonPath('assistant_message.role','assistant')
            ->assertJsonMissingPath('user_message.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.content','Проверь документ')->assertJsonPath('data.0.role','user');
    }

    public function test_material_upload_is_private_validated_and_owner_scoped(): void
    {
        Storage::fake('local'); [$user,$project]=$this->project(); $other=User::factory()->create();
        $material=$this->actingAs($user,'sanctum')->post("/api/expert/projects/{$project->public_id}/materials",['file'=>UploadedFile::fake()->create('evidence.pdf',10,'application/pdf')],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('original_name','evidence.pdf')->assertJsonMissingPath('storage_path');
        $record=$project->materials()->firstOrFail(); Storage::disk('local')->assertExists($record->storage_path);
        $this->get('/api/expert/materials/'.$material->json('public_id').'/download')->assertOk()->assertHeader('content-disposition');
        $this->actingAs($other,'sanctum')->get('/api/expert/materials/'.$material->json('public_id').'/download')->assertForbidden();
        Storage::disk('local')->delete($record->storage_path);
        $this->actingAs($user,'sanctum')->get('/api/expert/materials/'.$material->json('public_id').'/download')->assertNotFound();
        $this->actingAs($user,'sanctum')->post("/api/expert/projects/{$project->public_id}/materials",['file'=>UploadedFile::fake()->create('evil.exe',10,'application/x-msdownload')],['Accept'=>'application/json'])->assertUnprocessable();
        $this->post("/api/expert/projects/{$project->public_id}/materials",['file'=>UploadedFile::fake()->create('disguised.exe',10,'application/pdf')],['Accept'=>'application/json'])->assertUnprocessable();
    }

    public function test_private_image_content_is_owner_scoped_and_does_not_expose_storage_path(): void
    {
        Storage::fake('local'); [$user,$project]=$this->project(); $other=User::factory()->create();
        $material=$this->actingAs($user,'sanctum')->post("/api/expert/projects/{$project->public_id}/materials",[
            'file'=>UploadedFile::fake()->create('kitchen.jpg',10,'image/jpeg'),
        ],['Accept'=>'application/json'])->assertCreated()->assertJsonPath('original_name','kitchen.jpg')->assertJsonMissingPath('storage_path');
        $url='/api/expert/materials/'.$material->json('public_id').'/content';

        $this->get($url)->assertOk()->assertHeader('content-type','image/jpeg');
        $this->actingAs($other,'sanctum')->get($url)->assertForbidden();
        $record=$project->materials()->firstOrFail(); Storage::disk('local')->delete($record->storage_path);
        $this->actingAs($user,'sanctum')->get($url)->assertNotFound();
    }

    public function test_finding_bindings_reject_foreign_entities_and_protect_linked_material(): void
    {
        Storage::fake('local'); [$user,$project]=$this->project(); [, $foreign]=$this->project();
        $this->actingAs($user,'sanctum');
        $object=$project->researchObjects()->create(['name'=>'Объект']);
        $file=UploadedFile::fake()->create('photo.jpg',10,'image/jpeg');
        $materialId=$this->post("/api/expert/projects/{$project->public_id}/materials",['file'=>$file],['Accept'=>'application/json'])->assertCreated()->json('public_id');
        $material=$project->materials()->where('public_id',$materialId)->firstOrFail();
        $finding=$this->postJson("/api/expert/projects/{$project->public_id}/findings",['type'=>'defect','title'=>'Скол','research_object_public_id'=>$object->public_id,'material_public_ids'=>[$materialId]])->assertCreated()->assertJsonPath('status','expert_confirmed')->assertJsonPath('research_object.public_id',$object->public_id)->assertJsonPath('materials.0.public_id',$materialId);
        $this->deleteJson("/api/expert/materials/{$materialId}")->assertConflict(); Storage::disk('local')->assertExists($material->storage_path);
        $foreignObject=$foreign->researchObjects()->create(['name'=>'Чужой объект']);
        $this->patchJson('/api/expert/findings/'.$finding->json('public_id'),['research_object_public_id'=>$foreignObject->public_id])->assertUnprocessable();
        $foreignMaterial=$foreign->materials()->create(['uploaded_by'=>$foreign->user_id,'original_name'=>'foreign.pdf','storage_path'=>'expert/foreign.pdf','mime_type'=>'application/pdf','extension'=>'pdf','size'=>10,'category'=>'document','status'=>'uploaded']);
        $this->patchJson('/api/expert/findings/'.$finding->json('public_id'),['material_public_ids'=>[$foreignMaterial->public_id]])->assertUnprocessable();
    }

    public function test_project_delete_cascades_database_and_removes_binary_files(): void
    {
        Storage::fake('local'); [$user,$project]=$this->project(); $this->actingAs($user,'sanctum');
        $object=$project->researchObjects()->create(['name'=>'Объект']);
        $conversation=$project->conversations()->create(['title'=>'Общий анализ']);
        $message=$conversation->messages()->create(['role'=>'user','content'=>'Проверить']);
        $materialId=$this->post("/api/expert/projects/{$project->public_id}/materials",['file'=>UploadedFile::fake()->create('evidence.pdf',10,'application/pdf')],['Accept'=>'application/json'])->assertCreated()->json('public_id');
        $material=$project->materials()->where('public_id',$materialId)->firstOrFail();
        $finding=$project->findings()->create(['research_object_id'=>$object->id,'type'=>'fact','title'=>'Факт','status'=>'expert_confirmed','created_by'=>$user->id]);
        $finding->materials()->attach($material->id);
        $path=$material->storage_path;
        $this->deleteJson("/api/expert/projects/{$project->public_id}")->assertNoContent();
        $this->assertDatabaseMissing('expert_projects',['id'=>$project->id]);
        $this->assertDatabaseMissing('expert_research_objects',['id'=>$object->id]);
        $this->assertDatabaseMissing('expert_conversations',['id'=>$conversation->id]);
        $this->assertDatabaseMissing('expert_messages',['id'=>$message->id]);
        $this->assertDatabaseMissing('expert_project_materials',['id'=>$material->id]);
        $this->assertDatabaseMissing('expert_findings',['id'=>$finding->id]);
        $this->assertDatabaseMissing('expert_finding_material',['expert_finding_id'=>$finding->id,'expert_project_material_id'=>$material->id]);
        $this->assertDatabaseMissing('expert_storage_cleanup_tasks',['path'=>"expert/{$project->public_id}"]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_project_delete_journals_failed_physical_cleanup_for_retry(): void
    {
        [$user,$project]=$this->project();
        $directory="expert/{$project->public_id}";
        $disk=Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($directory)->andReturn(true);
        $disk->shouldReceive('deleteDirectory')->once()->with($directory)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->actingAs($user,'sanctum')->deleteJson("/api/expert/projects/{$project->public_id}")->assertNoContent();

        $this->assertDatabaseHas('expert_storage_cleanup_tasks',['path'=>$directory,'kind'=>'directory','attempts'=>1]);
    }

    public function test_unlinked_material_delete_removes_private_file(): void
    {
        Storage::fake('local'); [$user,$project]=$this->project(); $this->actingAs($user,'sanctum');
        $materialId=$this->post("/api/expert/projects/{$project->public_id}/materials",[
            'file'=>UploadedFile::fake()->create('evidence.pdf',10,'application/pdf'),
        ],['Accept'=>'application/json'])->assertCreated()->json('public_id');
        $material=$project->materials()->where('public_id',$materialId)->firstOrFail();

        $this->deleteJson("/api/expert/materials/{$materialId}")->assertNoContent();

        $this->assertDatabaseMissing('expert_project_materials',['id'=>$material->id]);
        $this->assertDatabaseMissing('expert_storage_cleanup_tasks',['path'=>$material->storage_path]);
        Storage::disk('local')->assertMissing($material->storage_path);
    }

    public function test_storage_cleanup_command_retries_journaled_private_file(): void
    {
        Storage::fake('local');
        $path='expert/'.Str::uuid().'/materials/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path,'private evidence');
        app(ExpertStorageCleanupService::class)->scheduleFile($path);

        $this->artisan('expert:cleanup-storage')->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('expert_storage_cleanup_tasks',['path'=>$path]);
    }

    public function test_user_hard_delete_removes_expert_dependencies_and_private_files(): void
    {
        Storage::fake('local');
        [$user,$project]=$this->project();
        $admin=User::factory()->create();
        $this->actingAs($user,'sanctum')->post("/api/expert/projects/{$project->public_id}/materials",[
            'file'=>UploadedFile::fake()->create('evidence.pdf',10,'application/pdf'),
        ],['Accept'=>'application/json'])->assertCreated();
        $project->findings()->create([
            'type'=>'fact','title'=>'Факт','status'=>'expert_confirmed','created_by'=>$user->id,
        ]);

        app(AdminUserService::class)->hardDeleteUser($user,$admin);

        $this->assertDatabaseMissing('users',['id'=>$user->id]);
        $this->assertDatabaseMissing('expert_projects',['id'=>$project->id]);
        Storage::disk('local')->assertMissing("expert/{$project->public_id}");
    }

    private function project(): array
    {
        $user=User::factory()->create();
        $project=ExpertProject::create(['user_id'=>$user->id,'name'=>'Проект','domain'=>'other','work_type'=>'other']);
        return [$user,$project];
    }
}
