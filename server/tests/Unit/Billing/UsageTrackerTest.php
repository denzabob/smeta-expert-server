<?php

namespace Tests\Unit\Billing;

use App\Models\Project;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingContext;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\UsageTracker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class UsageTrackerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tracking_enabled_creates_usage_event(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();

        app(UsageTracker::class)->record(BillingCodes::METRIC_PROJECTS_CREATED, 1, [
            'user' => $user,
            'feature_code' => BillingCodes::FEATURE_PROJECTS_CREATE,
            'unit' => 'count',
            'source' => 'test',
            'metadata' => ['case' => 'event'],
        ]);

        $event = UsageEvent::query()->where('metric_code', BillingCodes::METRIC_PROJECTS_CREATED)->first();

        $this->assertNotNull($event);
        $this->assertSame('user', $event->owner_type);
        $this->assertSame((int) $user->id, (int) $event->owner_id);
        $this->assertSame(BillingCodes::FEATURE_PROJECTS_CREATE, $event->feature_code);
        $this->assertSame('test', $event->source);
        $this->assertEquals(1.0, (float) $event->quantity);
    }

    public function test_tracking_creates_and_updates_monthly_counter(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();
        $occurredAt = Carbon::create(2026, 4, 15, 12, 0, 0);

        $tracker = app(UsageTracker::class);
        $tracker->record(BillingCodes::METRIC_PDF_SMETA_GENERATED, 1, [
            'user' => $user,
            'occurred_at' => $occurredAt,
        ]);
        $tracker->record(BillingCodes::METRIC_PDF_SMETA_GENERATED, 2, [
            'user' => $user,
            'occurred_at' => $occurredAt,
        ]);

        $counter = UsageCounter::query()
            ->where('owner_type', 'user')
            ->where('owner_id', $user->id)
            ->where('metric_code', BillingCodes::METRIC_PDF_SMETA_GENERATED)
            ->first();

        $this->assertNotNull($counter);
        $this->assertEquals(3.0, (float) $counter->quantity);
        $this->assertTrue($counter->period_start->isSameDay($occurredAt->copy()->startOfMonth()));
        $this->assertTrue($counter->period_end->isSameDay($occurredAt->copy()->endOfMonth()));
    }

    public function test_project_context_records_project_id(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => 'BILL-2',
            'expert_name' => 'Billing Expert',
            'address' => 'Billing address',
        ]);

        app(UsageTracker::class)->record(BillingCodes::METRIC_EVIDENCE_RUNS_CREATED, 1, [
            'project' => $project,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
        ]);

        $event = UsageEvent::query()->where('metric_code', BillingCodes::METRIC_EVIDENCE_RUNS_CREATED)->first();

        $this->assertNotNull($event);
        $this->assertSame((int) $project->id, (int) $event->project_id);
        $this->assertSame(Project::class, $event->subject_type);
        $this->assertSame((int) $project->id, (int) $event->subject_id);
    }

    public function test_tracking_error_does_not_escape_record_call(): void
    {
        config(['billing.track_usage' => true]);

        $tracker = new class(app(BillingContextResolver::class)) extends UsageTracker
        {
            protected function writeUsage(
                BillingContext $billingContext,
                string $metricCode,
                float $quantity,
                array $context = [],
            ): void {
                throw new RuntimeException('Simulated usage write failure.');
            }
        };

        $user = User::factory()->create();

        $tracker->record(BillingCodes::METRIC_PRICE_IMPORTS_CREATED, 1, [
            'user' => $user,
        ]);

        $this->assertTrue(true);
    }
}
