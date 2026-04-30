<?php
// app/Policies/ProjectPolicy.php

namespace App\Policies;

use App\Exceptions\ProjectWorkspaceReadOnlyException;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\ProjectWorkspaceAccessService;
use Illuminate\Support\Facades\Log;

class ProjectPolicy
{
    /**
     * Определяет, может ли пользователь просматривать проект.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }

    /**
     * Определяет, может ли пользователь обновлять проект.
     */
    public function update(User $user, Project $project): bool
    {
        $authorized = $user->id === $project->user_id;
        
        if (!$authorized) {
            Log::warning('ProjectPolicy::update unauthorized', [
                'user_id' => $user->id,
                'project_user_id' => $project->user_id,
                'project_id' => $project->id,
            ]);
        }
        
        if (! $authorized) {
            return false;
        }

        $billingStatus = app(ProjectWorkspaceAccessService::class)->editStatus($project);

        if ($billingStatus['blocked']) {
            throw new ProjectWorkspaceReadOnlyException($billingStatus);
        }

        return true;
    }

    /**
     * Определяет, может ли пользователь удалять проект.
     */
    public function delete(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }

    /**
     * Определяет, может ли пользователь восстанавливать проект.
     */
    public function restore(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }

    /**
     * Определяет, может ли пользователь навсегда удалять проект.
     */
    public function forceDelete(User $user, Project $project): bool
    {
        return $user->id === $project->user_id;
    }
}
