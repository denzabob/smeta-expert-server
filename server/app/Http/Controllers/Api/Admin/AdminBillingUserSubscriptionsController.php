<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminBillingUserSubscriptionsController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->subscriptionPayload($user));
    }

    public function assign(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'plan_code' => 'required|string|exists:billing_plans,code',
            'period' => 'nullable|in:month,year,custom',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'reason' => 'nullable|string|max:1000',
        ]);

        if (($validated['period'] ?? null) === 'custom' && empty($validated['ends_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => 'ends_at is required when period is custom.',
            ]);
        }

        $subscription = DB::transaction(function () use ($request, $user, $validated) {
            $plan = BillingPlan::query()
                ->where('code', $validated['plan_code'])
                ->firstOrFail();

            $oldSubscription = $this->activeSubscriptionQuery($user)->lockForUpdate()->first();
            $periodStart = isset($validated['starts_at']) ? Carbon::parse($validated['starts_at']) : now();
            $periodEnd = $this->resolvePeriodEnd($periodStart, $validated['period'] ?? 'month', $validated['ends_at'] ?? null);

            if ($oldSubscription) {
                $oldPeriodEnd = $oldSubscription->current_period_end?->copy();
                $oldSubscription->forceFill([
                    'status' => 'replaced',
                    'current_period_end' => now(),
                ])->save();

                $this->recordEvent(
                    user: $user,
                    admin: $request->user(),
                    subscription: $oldSubscription,
                    eventType: 'replaced',
                    oldPlanCode: $oldSubscription->plan_code,
                    newPlanCode: $plan->code,
                    oldStatus: 'active',
                    newStatus: 'replaced',
                    oldPeriodEnd: $oldPeriodEnd,
                    newPeriodEnd: $oldSubscription->current_period_end,
                    reason: $validated['reason'] ?? null,
                );
            }

            $subscription = BillingSubscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'status' => 'active',
                'source' => 'manual',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'overrides_json' => [
                    'source' => 'manual',
                    'period' => $validated['period'] ?? 'month',
                    'reason' => $validated['reason'] ?? null,
                    'admin_user_id' => $request->user()->id,
                ],
            ]);

            $this->recordEvent(
                user: $user,
                admin: $request->user(),
                subscription: $subscription,
                eventType: 'assigned',
                oldPlanCode: $oldSubscription?->plan_code,
                newPlanCode: $subscription->plan_code,
                oldStatus: $oldSubscription?->status,
                newStatus: $subscription->status,
                oldPeriodEnd: $oldSubscription?->current_period_end,
                newPeriodEnd: $subscription->current_period_end,
                reason: $validated['reason'] ?? null,
            );

            return $subscription;
        });

        return response()->json($this->subscriptionPayload($user, $subscription->refresh()->load('plan')), 201);
    }

    public function extend(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'months' => 'nullable|integer|min:0|max:36',
            'days' => 'nullable|integer|min:0|max:365',
            'reason' => 'nullable|string|max:1000',
        ]);

        $months = (int) ($validated['months'] ?? 0);
        $days = (int) ($validated['days'] ?? 0);

        if ($months <= 0 && $days <= 0) {
            throw ValidationException::withMessages([
                'period' => 'At least one of months or days must be greater than zero.',
            ]);
        }

        $subscription = DB::transaction(function () use ($request, $user, $validated, $months, $days) {
            $subscription = $this->activeSubscriptionQuery($user)->lockForUpdate()->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'subscription' => 'Active subscription was not found.',
                ]);
            }

            $oldPeriodEnd = $subscription->current_period_end?->copy();
            $base = $subscription->current_period_end && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end->copy()
                : now();
            $newPeriodEnd = $base->copy()->addMonths($months)->addDays($days);

            $subscription->forceFill([
                'current_period_end' => $newPeriodEnd,
            ])->save();

            $this->recordEvent(
                user: $user,
                admin: $request->user(),
                subscription: $subscription,
                eventType: 'extended',
                oldPlanCode: $subscription->plan_code,
                newPlanCode: $subscription->plan_code,
                oldStatus: $subscription->status,
                newStatus: $subscription->status,
                oldPeriodEnd: $oldPeriodEnd,
                newPeriodEnd: $subscription->current_period_end,
                reason: $validated['reason'] ?? null,
                context: ['months' => $months, 'days' => $days],
            );

            return $subscription;
        });

        return response()->json($this->subscriptionPayload($user, $subscription->refresh()->load('plan')));
    }

    public function cancel(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $subscription = DB::transaction(function () use ($request, $user, $validated) {
            $subscription = $this->activeSubscriptionQuery($user)->lockForUpdate()->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'subscription' => 'Active subscription was not found.',
                ]);
            }

            $oldPeriodEnd = $subscription->current_period_end?->copy();
            $subscription->forceFill([
                'status' => 'canceled',
                'current_period_end' => now(),
            ])->save();

            $this->recordEvent(
                user: $user,
                admin: $request->user(),
                subscription: $subscription,
                eventType: 'canceled',
                oldPlanCode: $subscription->plan_code,
                newPlanCode: $subscription->plan_code,
                oldStatus: 'active',
                newStatus: 'canceled',
                oldPeriodEnd: $oldPeriodEnd,
                newPeriodEnd: $subscription->current_period_end,
                reason: $validated['reason'] ?? null,
            );

            return $subscription;
        });

        return response()->json($this->subscriptionPayload($user, $subscription->refresh()->load('plan')));
    }

    public function legacy(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        $subscription = DB::transaction(function () use ($request, $user, $validated) {
            $legacyPlan = BillingPlan::query()
                ->where('code', 'legacy_unlimited')
                ->firstOrFail();
            $oldSubscription = $this->activeSubscriptionQuery($user)->lockForUpdate()->first();

            if ($oldSubscription) {
                $oldPeriodEnd = $oldSubscription->current_period_end?->copy();
                $oldSubscription->forceFill([
                    'status' => 'replaced',
                    'current_period_end' => now(),
                ])->save();

                $this->recordEvent(
                    user: $user,
                    admin: $request->user(),
                    subscription: $oldSubscription,
                    eventType: 'replaced',
                    oldPlanCode: $oldSubscription->plan_code,
                    newPlanCode: $legacyPlan->code,
                    oldStatus: 'active',
                    newStatus: 'replaced',
                    oldPeriodEnd: $oldPeriodEnd,
                    newPeriodEnd: $oldSubscription->current_period_end,
                    reason: $validated['reason'] ?? null,
                );
            }

            $subscription = BillingSubscription::query()->create([
                'user_id' => $user->id,
                'plan_id' => $legacyPlan->id,
                'plan_code' => $legacyPlan->code,
                'status' => 'active',
                'source' => 'manual',
                'current_period_start' => now(),
                'current_period_end' => null,
                'overrides_json' => [
                    'source' => 'manual',
                    'legacy' => true,
                    'reason' => $validated['reason'] ?? null,
                    'admin_user_id' => $request->user()->id,
                ],
            ]);

            $this->recordEvent(
                user: $user,
                admin: $request->user(),
                subscription: $subscription,
                eventType: 'switched_to_legacy',
                oldPlanCode: $oldSubscription?->plan_code,
                newPlanCode: $subscription->plan_code,
                oldStatus: $oldSubscription?->status,
                newStatus: $subscription->status,
                oldPeriodEnd: $oldSubscription?->current_period_end,
                newPeriodEnd: $subscription->current_period_end,
                reason: $validated['reason'] ?? null,
            );

            return $subscription;
        });

        return response()->json($this->subscriptionPayload($user, $subscription->refresh()->load('plan')), 201);
    }

    public function history(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $events = BillingSubscriptionEvent::query()
            ->with(['adminUser:id,name,email'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (BillingSubscriptionEvent $event) => $this->eventPayload($event))
            ->values();

        return response()->json([
            'data' => $events,
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    private function activeSubscriptionQuery(User $user)
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->orderByDesc('current_period_end')
            ->orderByDesc('id');
    }

    private function subscriptionPayload(User $user, ?BillingSubscription $subscription = null): array
    {
        $subscription ??= $this->activeSubscriptionQuery($user)
            ->with('plan')
            ->first();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'subscription' => $subscription ? $this->subscriptionSummary($subscription) : null,
            'plan' => $subscription?->plan ? [
                'code' => $subscription->plan->code,
                'name' => $subscription->plan->name,
                'metadata_json' => $subscription->plan->metadata_json ?? [],
            ] : null,
            'effective_plan_code' => $subscription?->plan_code ?? config('billing.default_plan', 'legacy_unlimited'),
        ];
    }

    private function subscriptionSummary(BillingSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'plan_code' => $subscription->plan_code,
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start?->toDateTimeString(),
            'current_period_end' => $subscription->current_period_end?->toDateTimeString(),
            'source' => $subscription->source,
            'created_at' => $subscription->created_at?->toDateTimeString(),
            'updated_at' => $subscription->updated_at?->toDateTimeString(),
        ];
    }

    private function eventPayload(BillingSubscriptionEvent $event): array
    {
        return [
            'id' => $event->id,
            'subscription_id' => $event->subscription_id,
            'event_type' => $event->event_type,
            'admin_user' => $event->adminUser ? [
                'id' => $event->adminUser->id,
                'name' => $event->adminUser->name,
                'email' => $event->adminUser->email,
            ] : null,
            'old_plan_code' => $event->old_plan_code,
            'new_plan_code' => $event->new_plan_code,
            'old_status' => $event->old_status,
            'new_status' => $event->new_status,
            'old_period_end' => $event->old_period_end?->toDateTimeString(),
            'new_period_end' => $event->new_period_end?->toDateTimeString(),
            'reason' => $event->reason,
            'context_json' => $event->context_json ?? [],
            'created_at' => $event->created_at?->toDateTimeString(),
        ];
    }

    private function resolvePeriodEnd(Carbon $periodStart, string $period, ?string $endsAt): Carbon
    {
        if ($endsAt) {
            return Carbon::parse($endsAt);
        }

        return match ($period) {
            'year' => $periodStart->copy()->addYear(),
            'custom' => throw ValidationException::withMessages(['ends_at' => 'ends_at is required when period is custom.']),
            default => $periodStart->copy()->addMonth(),
        };
    }

    private function recordEvent(
        User $user,
        User $admin,
        BillingSubscription $subscription,
        string $eventType,
        ?string $oldPlanCode,
        ?string $newPlanCode,
        ?string $oldStatus,
        ?string $newStatus,
        ?Carbon $oldPeriodEnd,
        ?Carbon $newPeriodEnd,
        ?string $reason,
        array $context = [],
    ): void {
        BillingSubscriptionEvent::query()->create([
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'subscription_id' => $subscription->id,
            'event_type' => $eventType,
            'old_plan_code' => $oldPlanCode,
            'new_plan_code' => $newPlanCode,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'old_period_end' => $oldPeriodEnd,
            'new_period_end' => $newPeriodEnd,
            'reason' => $reason,
            'context_json' => $context,
        ]);
    }
}
