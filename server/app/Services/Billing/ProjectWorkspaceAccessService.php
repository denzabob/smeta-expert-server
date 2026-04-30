<?php

namespace App\Services\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\User;

class ProjectWorkspaceAccessService
{
    public function createStatus(User $user): array
    {
        $status = $this->status($user);
        $blocked = $this->isEnforced() && $status['limit'] !== null && $status['owned_projects'] >= $status['limit'];
        $readOnly = $this->isEnforced() && $status['limit'] !== null && $status['owned_projects'] > $status['limit'];

        return array_merge($status, [
            'blocked' => $blocked,
            'read_only' => $readOnly,
            'reason' => $blocked ? ($readOnly ? 'active_projects_limit_exceeded' : 'active_projects_limit_reached') : 'allowed',
            'message' => $blocked ? $this->message($status) : null,
        ]);
    }

    public function editStatus(Project $project): array
    {
        $status = $this->status($project->user);
        $blocked = $this->isEnforced()
            && $status['limit'] !== null
            && $status['owned_projects'] > $status['limit'];

        return array_merge($status, [
            'blocked' => $blocked,
            'read_only' => $blocked,
            'reason' => $blocked ? 'active_projects_limit_exceeded' : 'allowed',
            'message' => $blocked ? $this->message($status) : null,
        ]);
    }

    public function responsePayload(array $status): array
    {
        return [
            'message' => $status['message'] ?? $this->message($status),
            'billing' => [
                'read_only' => (bool) ($status['read_only'] ?? $status['blocked'] ?? false),
                'reason' => $status['reason'] ?? null,
                'limit_key' => $status['limit_key'] ?? BillingCodes::CAP_PROJECTS_MAX_OWNED,
                'limit' => $status['limit'] ?? null,
                'owned_projects' => $status['owned_projects'] ?? 0,
                'active_projects' => $status['active_projects'] ?? 0,
            ],
        ];
    }

    private function status(User $user): array
    {
        $plan = $this->resolvePlan($user);
        $limits = $plan?->metadata_json['limits'] ?? [];
        $limit = $this->limitValue($limits);
        $ownedProjects = Project::query()
            ->where('user_id', $user->id)
            ->count();

        $activeProjects = Project::query()
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->count();

        return [
            'plan_code' => $plan?->code ?? (string) config('billing.default_plan', 'legacy_unlimited'),
            'limit_key' => $this->limitKey($limits),
            'limit' => $limit,
            'owned_projects' => $ownedProjects,
            'active_projects' => $activeProjects,
        ];
    }

    private function resolvePlan(User $user): ?BillingPlan
    {
        $subscription = BillingSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->orderByDesc('current_period_end')
            ->orderByDesc('id')
            ->first();

        if ($subscription?->plan) {
            return $subscription->plan;
        }

        $planCode = $subscription?->plan_code ?: config('billing.default_plan', 'legacy_unlimited');

        return BillingPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();
    }

    private function limitValue(array $limits): ?int
    {
        $key = $this->limitKey($limits);

        if ($key === null || $limits[$key] === null || $limits[$key] === '') {
            return null;
        }

        return (int) $limits[$key];
    }

    private function limitKey(array $limits): ?string
    {
        if (array_key_exists(BillingCodes::CAP_PROJECTS_MAX_OWNED, $limits)) {
            return BillingCodes::CAP_PROJECTS_MAX_OWNED;
        }

        if (array_key_exists(BillingCodes::CAP_PROJECTS_MAX_ACTIVE, $limits)) {
            return BillingCodes::CAP_PROJECTS_MAX_ACTIVE;
        }

        return null;
    }

    private function isEnforced(): bool
    {
        return (bool) config('billing.enabled', false)
            && (bool) config('billing.enforce_limits', false);
    }

    private function message(array $status): string
    {
        $limit = (int) ($status['limit'] ?? 0);
        $ownedProjects = (int) ($status['owned_projects'] ?? $status['active_projects'] ?? 0);

        return "Доступен режим просмотра. На текущем тарифе доступно проектов: {$limit}. Сейчас в аккаунте: {$ownedProjects}. Вы можете просматривать проекты, но создание и редактирование временно ограничены. Выберите подходящий тариф, чтобы продолжить работу.";
    }
}
