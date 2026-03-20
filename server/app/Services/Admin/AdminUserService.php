<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminUserService
{
    /**
     * Log an admin action to the audit log.
     */
    public function audit(
        int $adminUserId,
        ?int $targetUserId,
        string $action,
        string $result = 'success',
        ?string $reason = null,
        ?array $details = null,
        ?string $ip = null
    ): AdminAuditLog {
        return AdminAuditLog::create([
            'admin_user_id' => $adminUserId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'details' => $details,
            'ip_address' => $ip,
        ]);
    }

    /**
     * Block a user account.
     */
    public function blockUser(User $targetUser, User $admin, string $reason, ?string $ip = null): void
    {
        DB::transaction(function () use ($targetUser, $admin, $reason, $ip) {
            $targetUser->update([
                'auth_status' => 'blocked',
                'blocked_reason' => $reason,
                'blocked_by' => $admin->id,
                'blocked_at' => now(),
            ]);

            // Revoke all Sanctum tokens
            $targetUser->tokens()->delete();

            // Clear current session
            $targetUser->update(['current_session_id' => null]);

            $this->audit($admin->id, $targetUser->id, 'block', 'success', $reason, null, $ip);
        });
    }

    /**
     * Unblock a user account.
     */
    public function unblockUser(User $targetUser, User $admin, ?string $reason = null, ?string $ip = null): void
    {
        DB::transaction(function () use ($targetUser, $admin, $reason, $ip) {
            $targetUser->update([
                'auth_status' => 'active',
                'blocked_reason' => null,
                'blocked_by' => null,
                'blocked_at' => null,
            ]);

            $this->audit($admin->id, $targetUser->id, 'unblock', 'success', $reason, null, $ip);
        });
    }

    /**
     * Soft delete a user account.
     */
    public function softDeleteUser(User $targetUser, User $admin, ?string $reason = null, ?string $ip = null): void
    {
        DB::transaction(function () use ($targetUser, $admin, $reason, $ip) {
            // Revoke all tokens
            $targetUser->tokens()->delete();
            $targetUser->update(['current_session_id' => null]);

            // Soft delete
            $targetUser->delete();

            $this->audit($admin->id, $targetUser->id, 'soft_delete', 'success', $reason, null, $ip);
        });
    }

    /**
     * Hard delete a user and all related data.
     */
    public function hardDeleteUser(User $targetUser, User $admin, ?string $reason = null, ?string $ip = null): void
    {
        DB::transaction(function () use ($targetUser, $admin, $reason, $ip) {
            $userId = $targetUser->id;
            $userName = $targetUser->name;
            $userEmail = $targetUser->email;

            // Collect dependency info before deletion for audit
            $dependencies = $this->getDependencies($targetUser);

            // Delete all related data that won't cascade automatically
            $this->deleteRelatedData($targetUser);

            // Force delete the user (bypasses soft delete)
            $targetUser->forceDelete();

            $this->audit(
                $admin->id,
                null, // user is deleted
                'hard_delete',
                'success',
                $reason,
                [
                    'deleted_user_id' => $userId,
                    'deleted_user_name' => $userName,
                    'deleted_user_email' => $userEmail,
                    'deleted_dependencies' => $dependencies,
                ],
                $ip
            );
        });
    }

    /**
     * Get all dependencies for a user (for preview).
     */
    public function getDependencies(User $user): array
    {
        $userId = $user->id;

        return [
            'projects' => DB::table('projects')->where('user_id', $userId)->count(),
            'operations' => DB::table('operations')->where('user_id', $userId)->count(),
            'suppliers' => DB::table('suppliers')->where('user_id', $userId)->count(),
            'ai_logs' => DB::table('ai_logs')->where('user_id', $userId)->count(),
            'import_sessions' => DB::table('import_sessions')->where('user_id', $userId)->count(),
            'ideas' => DB::table('ideas')->where('user_id', $userId)->count(),
            'idea_votes' => DB::table('idea_votes')->where('user_id', $userId)->count(),
            'idea_comments' => DB::table('idea_comments')->where('user_id', $userId)->count(),
            'trusted_devices' => DB::table('trusted_devices')->where('user_id', $userId)->count(),
            'social_accounts' => DB::table('social_accounts')->where('user_id', $userId)->count(),
            'tokens' => $user->tokens()->count(),
            'notifications' => DB::table('user_notifications')->where('user_id', $userId)->count(),
            'user_settings' => DB::table('user_settings')->where('user_id', $userId)->count(),
            'user_material_library' => DB::table('user_material_library')->where('user_id', $userId)->count(),
            'operation_groups' => DB::table('operation_groups')->where('user_id', $userId)->count(),
            'chrome_ext_logs' => DB::table('chrome_ext_logs')->where('user_id', $userId)->count(),
            'furniture_modules' => DB::table('furniture_modules')->where('user_id', $userId)->count(),
            'detail_types' => DB::table('detail_types')->where('user_id', $userId)->count(),
            'revision_runs' => DB::table('revision_runs')->where('initiator_user_id', $userId)->count(),
            'collect_profiles' => DB::table('parser_supplier_collect_profiles')->where('user_id', $userId)->count(),
            'price_import_sessions' => DB::table('price_import_sessions')->where('user_id', $userId)->count(),
            'project_revisions' => DB::table('project_revisions')->where('created_by_user_id', $userId)->count(),
        ];
    }

    /**
     * Delete all related data for a user that won't cascade automatically via FK.
     * Called inside a transaction by hardDeleteUser.
     */
    private function deleteRelatedData(User $user): void
    {
        $userId = $user->id;

        // Tables without cascade FK - delete manually
        // ai_logs - set null for audit preservation
        DB::table('ai_logs')->where('user_id', $userId)->update(['user_id' => null]);

        // auth_verification_challenges - can be deleted
        DB::table('auth_verification_challenges')->where('user_id', $userId)->delete();

        // project_revisions has restrict - need to nullify user reference
        DB::table('project_revisions')->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);

        // Tables without FK that reference user_id - delete
        DB::table('projects')->where('user_id', $userId)->get()->each(function ($project) {
            // Delete project cascade chain manually
            $projectId = $project->id;
            $revisionIds = DB::table('project_revisions')->where('project_id', $projectId)->pluck('id');

            // Delete publication views -> publications
            if ($revisionIds->isNotEmpty()) {
                $publicationIds = DB::table('revision_publications')->whereIn('project_revision_id', $revisionIds)->pluck('id');
                if ($publicationIds->isNotEmpty()) {
                    DB::table('revision_publication_views')->whereIn('revision_publication_id', $publicationIds)->delete();
                    DB::table('revision_publications')->whereIn('id', $publicationIds)->delete();
                }
                DB::table('project_revisions')->whereIn('id', $revisionIds)->delete();
            }

            // Delete revision runs
            $runIds = DB::table('revision_runs')->where('project_id', $projectId)->pluck('id');
            if ($runIds->isNotEmpty()) {
                DB::table('revision_run_items')->whereIn('revision_run_id', $runIds)->delete();
                DB::table('revision_runs')->whereIn('id', $runIds)->delete();
            }

            // Delete project positions and their nested data
            $positionIds = DB::table('project_positions')->where('project_id', $projectId)->pluck('id');
            if ($positionIds->isNotEmpty()) {
                DB::table('project_position_price_quotes')->whereIn('project_position_id', $positionIds)->delete();
                DB::table('project_positions')->whereIn('id', $positionIds)->delete();
            }

            // Other project-related tables
            DB::table('expenses')->where('project_id', $projectId)->delete();
            DB::table('project_fittings')->where('project_id', $projectId)->delete();
            DB::table('project_manual_operations')->where('project_id', $projectId)->delete();
            DB::table('project_normohour_sources')->where('project_id', $projectId)->delete();
            DB::table('project_price_list_versions')->where('project_id', $projectId)->delete();
            DB::table('project_profile_rates')->where('project_id', $projectId)->delete();

            // Labor works
            $laborIds = DB::table('project_labor_works')->where('project_id', $projectId)->pluck('id');
            if ($laborIds->isNotEmpty()) {
                DB::table('project_labor_work_steps')->whereIn('project_labor_work_id', $laborIds)->delete();
                DB::table('project_labor_works')->whereIn('id', $laborIds)->delete();
            }

            DB::table('projects')->where('id', $projectId)->delete();
        });

        // Operations without FK cascade
        DB::table('operations')->where('user_id', $userId)->delete();

        // Furniture modules and cascade
        $moduleIds = DB::table('furniture_modules')->where('user_id', $userId)->pluck('id');
        if ($moduleIds->isNotEmpty()) {
            $detailIds = DB::table('details')->whereIn('module_id', $moduleIds)->pluck('id');
            if ($detailIds->isNotEmpty()) {
                DB::table('fittings')->whereIn('detail_id', $detailIds)->delete();
                DB::table('details')->whereIn('id', $detailIds)->delete();
            }
            DB::table('furniture_modules')->whereIn('id', $moduleIds)->delete();
        }

        // Detail types
        DB::table('detail_types')->where('user_id', $userId)->delete();

        // Materials - nullify curator
        DB::table('materials')->where('curator_user_id', $userId)->update(['curator_user_id' => null]);

        // Material dimension rules/parse failures - nullify user references
        DB::table('material_dimension_rules')->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);
        DB::table('material_dimension_rules')->where('updated_by_user_id', $userId)->update(['updated_by_user_id' => null]);
        DB::table('material_dimension_parse_failures')->where('resolved_by_user_id', $userId)->update(['resolved_by_user_id' => null]);
        DB::table('material_type_patterns')->where('created_by_user_id', $userId)->update(['created_by_user_id' => null]);
        DB::table('material_type_patterns')->where('updated_by_user_id', $userId)->update(['updated_by_user_id' => null]);

        // Evidence artifacts - nullify
        DB::table('evidence_artifacts')->where('created_by', $userId)->update(['created_by' => null]);

        // Notifications created_by - nullify
        DB::table('notifications')->where('created_by', $userId)->update(['created_by' => null]);

        // Admin audit logs - keep but target_user_id will be nullified via FK
    }

    /**
     * Bulk block users.
     */
    public function bulkBlock(array $userIds, User $admin, string $reason, ?string $ip = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($userIds as $userId) {
            try {
                $user = User::find($userId);
                if (!$user || $user->id === $admin->id || $user->isSuperAdmin()) {
                    $results['failed']++;
                    $results['errors'][] = "User #{$userId}: cannot block (protected or not found)";
                    continue;
                }
                $this->blockUser($user, $admin, $reason, $ip);
                $results['success']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "User #{$userId}: " . $e->getMessage();
                Log::error("Bulk block failed for user #{$userId}", ['error' => $e->getMessage()]);
            }
        }

        return $results;
    }

    /**
     * Bulk unblock users.
     */
    public function bulkUnblock(array $userIds, User $admin, ?string $reason = null, ?string $ip = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($userIds as $userId) {
            try {
                $user = User::find($userId);
                if (!$user) {
                    $results['failed']++;
                    continue;
                }
                $this->unblockUser($user, $admin, $reason, $ip);
                $results['success']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "User #{$userId}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Bulk soft delete users.
     */
    public function bulkSoftDelete(array $userIds, User $admin, ?string $reason = null, ?string $ip = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($userIds as $userId) {
            try {
                $user = User::find($userId);
                if (!$user || $user->id === $admin->id || $user->isSuperAdmin()) {
                    $results['failed']++;
                    $results['errors'][] = "User #{$userId}: cannot delete (protected or not found)";
                    continue;
                }
                $this->softDeleteUser($user, $admin, $reason, $ip);
                $results['success']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = "User #{$userId}: " . $e->getMessage();
            }
        }

        return $results;
    }
}
