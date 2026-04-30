<?php

namespace App\Services\Billing;

use App\Models\UsageCounter;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

class UsageTracker
{
    public function __construct(
        private readonly BillingContextResolver $contextResolver,
        private readonly BillingUsageExclusionService $usageExclusion,
    ) {}

    public function record(string $metricCode, int|float $quantity = 1, array $context = []): void
    {
        if (! (bool) config('billing.track_usage', true)) {
            return;
        }

        try {
            $billingContext = $this->contextResolver->fromArray($context);
            if ($this->usageExclusion->shouldIgnoreUserId($billingContext->userId)) {
                return;
            }

            $this->writeUsage($billingContext, $metricCode, (float) $quantity, $context);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function writeUsage(
        BillingContext $billingContext,
        string $metricCode,
        float $quantity,
        array $context = [],
    ): void {
        $occurredAt = $context['occurred_at'] ?? now();
        $occurredAt = $occurredAt instanceof CarbonInterface ? $occurredAt : Carbon::parse($occurredAt);
        $periodStart = $occurredAt->copy()->startOfMonth();
        $periodEnd = $occurredAt->copy()->endOfMonth();

        DB::transaction(function () use ($billingContext, $metricCode, $quantity, $context, $occurredAt, $periodStart, $periodEnd) {
            UsageEvent::query()->create([
                'owner_type' => $billingContext->ownerType,
                'owner_id' => $billingContext->ownerId,
                'user_id' => $billingContext->userId,
                'project_id' => $billingContext->projectId,
                'metric_code' => $metricCode,
                'feature_code' => $context['feature_code'] ?? null,
                'quantity' => $quantity,
                'unit' => $context['unit'] ?? 'count',
                'subject_type' => $context['subject_type'] ?? null,
                'subject_id' => $context['subject_id'] ?? null,
                'request_id' => $context['request_id'] ?? request()?->headers->get('X-Request-Id'),
                'idempotency_key' => $context['idempotency_key'] ?? null,
                'source' => $context['source'] ?? $billingContext->source,
                'metadata_json' => array_merge($billingContext->metadata, $context['metadata'] ?? []),
                'occurred_at' => $occurredAt,
            ]);

            $counter = UsageCounter::query()->firstOrNew([
                'owner_type' => $billingContext->ownerType,
                'owner_id' => $billingContext->ownerId,
                'metric_code' => $metricCode,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            $counter->quantity = (float) ($counter->quantity ?? 0) + $quantity;

            if (array_key_exists('limit_snapshot', $context)) {
                $counter->limit_snapshot = $context['limit_snapshot'];
            }

            $counter->save();
        });
    }
}
