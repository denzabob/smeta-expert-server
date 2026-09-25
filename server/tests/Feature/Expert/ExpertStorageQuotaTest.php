<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingGateService;
use App\Services\Billing\BillingUsageExclusionService;
use App\Services\Expert\ExpertStorageQuotaService;
use App\Services\Expert\ExpertStorageUsageService;
use App\Services\Expert\ExpertStorageUsageSnapshot;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ExpertStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.s1' => [
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
        ]]);
        Storage::fake('s1');
        Storage::fake('local');
        config([
            'expert.storage.disk' => 's1',
            'billing.enabled' => true,
            'billing.enforce_limits' => true,
            'billing.log_only' => false,
            'billing.user_ui_enabled' => true,
            'billing.default_plan' => 'quota-default',
            'billing.fail_open' => true,
        ]);
    }

    public function test_upload_is_allowed_at_the_exact_limit_and_snapshot_uses_plan_bytes(): void
    {
        [$user, $project] = $this->project();
        $file = UploadedFile::fake()->create('exact.pdf', 1, 'application/pdf');
        $this->subscribe($user, 'exact_plan', $file->getSize());

        $response = $this->upload($user, $project, $file);
        $response->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertOk()
            ->assertJsonPath('storage.used_bytes', $file->getSize())
            ->assertJsonPath('storage.limit_bytes', $file->getSize())
            ->assertJsonPath('storage.remaining_bytes', 0)
            ->assertJsonPath('storage.usage_percent', 100)
            ->assertJsonPath('storage.is_unlimited', false)
            ->assertJsonPath('storage.is_over_limit', false);
    }

    public function test_enforced_projected_usage_rejects_before_creating_an_s1_object(): void
    {
        [$user, $project] = $this->project();
        $file = UploadedFile::fake()->create('too-large.pdf', 2, 'application/pdf');
        $this->subscribe($user, 'small_plan', $file->getSize() - 1);

        $response = $this->upload($user, $project, $file);
        $response->assertUnprocessable()
            ->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED')
            ->assertJsonPath('storage.used_bytes', 0)
            ->assertJsonPath('storage.limit_bytes', $file->getSize() - 1)
            ->assertJsonPath('storage.requested_bytes', $file->getSize())
            ->assertJsonPath('storage.remaining_bytes', $file->getSize() - 1);

        $this->assertDatabaseCount('expert_project_materials', 0);
        $this->assertDatabaseMissing('expert_storage_usages', ['user_id' => $user->id]);
        $this->assertDatabaseCount('expert_storage_upload_reservations', 0);
        $this->assertSame([], Storage::disk('s1')->allFiles("expert/{$project->public_id}"));
    }

    public function test_sequential_batch_requests_keep_the_earlier_file_and_reject_the_next_one(): void
    {
        [$user, $project] = $this->project();
        $first = UploadedFile::fake()->create('first.pdf', 1, 'application/pdf');
        $second = UploadedFile::fake()->create('second.pdf', 1, 'application/pdf');
        $this->subscribe($user, 'batch_plan', $first->getSize());

        $this->upload($user, $project, $first)->assertCreated();
        $this->upload($user, $project, $second)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED');

        $this->assertDatabaseCount('expert_project_materials', 1);
        $this->assertSame($first->getSize(), app(ExpertStorageUsageService::class)->getUserUsage($user)->usedBytes);
    }

    public function test_unlimited_plan_accepts_a_large_projected_usage_decision(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'unlimited_storage', null);
        $decision = app(ExpertStorageQuotaService::class)->decide(
            $user,
            new ExpertStorageUsageSnapshot(0, null, null, null, 0),
            100 * 1024 * 1024 * 1024,
        );

        $this->assertTrue($decision->allowed);
        $this->assertTrue($decision->isUnlimited);
        $this->assertNull($decision->remainingBytes);
        $this->assertNull($decision->usagePercent);
    }

    public function test_default_plan_resolves_storage_bytes_without_an_active_subscription(): void
    {
        config(['billing.default_plan' => 'default_storage_plan']);
        $user = User::factory()->create();
        $this->subscribe($user, 'default_storage_plan', 4096);

        // The helper creates an active subscription; this user is meant to use
        // only BILLING_DEFAULT_PLAN, so remove it before asking for the snapshot.
        BillingSubscription::query()->where('user_id', $user->id)->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/expert/storage')
            ->assertOk()
            ->assertJsonPath('limit_bytes', 4096)
            ->assertJsonPath('is_unlimited', false);
    }

    public function test_non_enforced_billing_modes_allow_upload_but_visible_mode_reports_over_limit(): void
    {
        foreach (['off', 'admin_only', 'visible', 'checkout'] as $mode) {
            $this->setMode($mode);
            [$user, $project] = $this->project();
            $file = UploadedFile::fake()->create("{$mode}.pdf", 1, 'application/pdf');
            $this->subscribe($user, "{$mode}_plan", $file->getSize() - 1);

            $this->upload($user, $project, $file)->assertCreated();

            if ($mode === 'visible') {
                $this->actingAs($user, 'sanctum')
                    ->getJson('/api/expert/storage')
                    ->assertOk()
                    ->assertJsonPath('is_over_limit', true)
                    ->assertJsonPath('limit_bytes', $file->getSize() - 1);
            }
        }
    }

    public function test_billing_check_observes_locked_usage_inside_the_upload_transaction(): void
    {
        [$user, $project] = $this->project();
        $file = UploadedFile::fake()->create('locked.pdf', 1, 'application/pdf');
        $this->subscribe($user, 'locked_plan', $file->getSize());

        $storageRowLocked = false;
        DB::listen(static function (QueryExecuted $query) use (&$storageRowLocked): void {
            if (str_contains(strtolower($query->sql), 'expert_storage_usages')
                && str_contains(strtolower($query->sql), 'for update')) {
                $storageRowLocked = true;
            }
        });

        $original = app(BillingGateService::class);
        $this->mock(BillingGateService::class, function ($mock) use ($user, $original, &$storageRowLocked): void {
            $mock->shouldReceive('checkProjectedUsage')->once()->andReturnUsing(
                function ($checkedUser, $capability, $projected, $context, $recordEvent) use ($user, $original, &$storageRowLocked) {
                    $this->assertTrue($checkedUser->is($user));
                    $this->assertSame(BillingCodes::CAP_STORAGE_BYTES, $capability);
                    $this->assertTrue(DB::transactionLevel() >= 2);
                    $this->assertTrue($storageRowLocked);
                    $this->assertDatabaseHas('expert_storage_usages', ['user_id' => $user->id]);

                    return $original->checkProjectedUsage($checkedUser, $capability, $projected, $context, $recordEvent);
                },
            );
        });

        $this->upload($user, $project, $file)->assertCreated();
    }

    public function test_fail_open_and_fail_closed_follow_the_shared_billing_gate_policy(): void
    {
        foreach ([true, false] as $failOpen) {
            config(['billing.fail_open' => $failOpen]);
            [$user, $project] = $this->project();
            $file = UploadedFile::fake()->create($failOpen ? 'open.pdf' : 'closed.pdf', 1, 'application/pdf');
            $this->subscribe($user, $failOpen ? 'fail_open_plan' : 'fail_closed_plan', null);

            $gate = new class(app(BillingUsageExclusionService::class)) extends BillingGateService {
                protected function resolvePlan(User $user): ?BillingPlan
                {
                    throw new RuntimeException('Simulated billing plan lookup failure.');
                }
            };
            app()->instance(BillingGateService::class, $gate);

            $response = $this->upload($user, $project, $file);
            if ($failOpen) {
                $response->assertCreated();
            } else {
                $response->assertStatus(503)->assertJsonPath('code', 'BILLING_LIMIT_CHECK_FAILED');
            }

            app()->forgetInstance(BillingGateService::class);
        }
    }

    public function test_downgrade_keeps_existing_material_readable_and_delete_frees_usage(): void
    {
        [$user, $project] = $this->project();
        $file = UploadedFile::fake()->create('kept.pdf', 1, 'application/pdf');
        $plan = $this->subscribe($user, 'downgrade_plan', $file->getSize());
        $uploaded = $this->upload($user, $project, $file)->assertCreated();
        $material = ExpertProjectMaterial::query()->where('public_id', $uploaded->json('public_id'))->firstOrFail();

        $plan->metadata_json = ['limits' => [BillingCodes::CAP_STORAGE_BYTES => 0]];
        $plan->save();

        $this->actingAs($user, 'sanctum')->getJson("/api/expert/materials/{$material->public_id}")->assertOk();
        $this->actingAs($user, 'sanctum')->get("/api/expert/materials/{$material->public_id}/download")->assertOk();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/expert/storage')
            ->assertOk()
            ->assertJsonPath('used_bytes', $file->getSize())
            ->assertJsonPath('limit_bytes', 0)
            ->assertJsonPath('is_over_limit', true);

        $blockedFile = UploadedFile::fake()->create('blocked-after-downgrade.pdf', 1, 'application/pdf');
        $this->upload($user, $project, $blockedFile)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/expert/materials/{$material->public_id}")->assertNoContent();
        $this->actingAs($user, 'sanctum')->getJson('/api/expert/storage')->assertJsonPath('used_bytes', 0);
    }

    public function test_storage_snapshot_remains_available_when_billing_ui_is_off_without_showing_plan_limit(): void
    {
        config(['billing.user_ui_enabled' => false]);
        [$user] = $this->project();
        app(ExpertStorageUsageService::class)->increment($user, 1234);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/expert/storage')
            ->assertOk()
            ->assertJsonPath('used_bytes', 1234)
            ->assertJsonPath('limit_bytes', null)
            ->assertJsonPath('limit_visible', false);

        $this->actingAs($user, 'sanctum')->getJson('/api/billing/me')->assertNotFound();
    }

    private function setMode(string $mode): void
    {
        config([
            'billing.enabled' => $mode !== 'off',
            'billing.enforce_limits' => $mode === 'enforced',
            'billing.log_only' => $mode !== 'enforced',
            'billing.user_ui_enabled' => in_array($mode, ['visible', 'checkout', 'enforced'], true),
        ]);
    }

    /** @return array{User, ExpertProject} */
    private function project(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::query()->create([
            'user_id' => $user->id,
            'name' => 'Storage quota project',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return [$user, $project];
    }

    private function subscribe(User $user, string $planCode, ?int $storageBytes): BillingPlan
    {
        $plan = BillingPlan::query()->create([
            'code' => $planCode,
            'name' => $planCode,
            'is_active' => true,
            'metadata_json' => ['limits' => [BillingCodes::CAP_STORAGE_BYTES => $storageBytes]],
        ]);
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'test',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $plan;
    }

    private function upload(User $user, ExpertProject $project, UploadedFile $file)
    {
        return $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => $file],
            ['Accept' => 'application/json'],
        );
    }
}
