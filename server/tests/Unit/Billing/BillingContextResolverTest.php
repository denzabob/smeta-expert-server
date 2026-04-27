<?php

namespace Tests\Unit\Billing;

use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BillingContextResolverTest extends TestCase
{
    use DatabaseTransactions;

    public function test_from_user_returns_user_owner_context(): void
    {
        $user = User::factory()->create();

        $context = app(BillingContextResolver::class)->fromUser($user);

        $this->assertSame('user', $context->ownerType);
        $this->assertSame((int) $user->id, $context->ownerId);
        $this->assertSame((int) $user->id, $context->userId);
        $this->assertNull($context->projectId);
    }

    public function test_from_project_returns_project_owner_and_project_id(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => 'BILL-1',
            'expert_name' => 'Billing Expert',
            'address' => 'Billing address',
        ]);

        $context = app(BillingContextResolver::class)->fromProject($project);

        $this->assertSame('user', $context->ownerType);
        $this->assertSame((int) $user->id, $context->ownerId);
        $this->assertSame((int) $user->id, $context->userId);
        $this->assertSame((int) $project->id, $context->projectId);
    }
}
