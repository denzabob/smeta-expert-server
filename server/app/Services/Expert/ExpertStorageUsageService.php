<?php

declare(strict_types=1);
namespace App\Services\Expert;

use App\Models\User;
use App\Services\Storage\StorageUsageService;
use Illuminate\Support\Facades\DB;

/** Compatibility API; the account registry is the sole accounting source. */
final class ExpertStorageUsageService
{
    public function __construct(private readonly StorageUsageService $usage) {}

    public function getUserUsage(User|int $user): ExpertStorageUsageSnapshot
    {
        $row = $this->usage->getUserUsage($this->id($user));
        return new ExpertStorageUsageSnapshot($row['used_bytes'], null, null, null, $row['files_count'], $row['reserved_bytes']);
    }

    public function lockUserUsage(User|int $user): ExpertStorageUsageSnapshot
    {
        $this->usage->lockUserUsage($this->id($user));
        return $this->getUserUsage($user);
    }

    public function recalculate(User|int $user): ExpertStorageUsageSnapshot
    {
        $this->usage->recalculate($this->id($user));
        return $this->getUserUsage($user);
    }

    public function reconcileUser(User|int $user, bool $dryRun = false): array
    {
        $id = $this->id($user);
        $audit = $this->usage->audit($id);
        $before = $audit['recorded'] ?? ['used_bytes' => 0, 'files_count' => 0, 'reserved_bytes' => 0];
        $calculated = $audit['calculated'];
        if (!$dryRun) {
            $calculated = $this->usage->recalculate($id);
        }
        return [
            'user_id' => $id, 'recorded_bytes' => $before['used_bytes'], 'calculated_bytes' => $calculated['used_bytes'],
            'difference_bytes' => $calculated['used_bytes'] - $before['used_bytes'],
            'recorded_materials_count' => $before['files_count'], 'calculated_materials_count' => $calculated['files_count'],
            'difference_materials_count' => $calculated['files_count'] - $before['files_count'],
            'recorded_reserved_bytes' => $before['reserved_bytes'], 'calculated_reserved_bytes' => $calculated['reserved_bytes'],
            'difference_reserved_bytes' => $calculated['reserved_bytes'] - $before['reserved_bytes'],
            'changed' => $before['used_bytes'] !== $calculated['used_bytes'] || $before['files_count'] !== $calculated['files_count'] || $before['reserved_bytes'] !== $calculated['reserved_bytes'],
            'dry_run' => $dryRun,
        ];
    }

    public function recalculateAll(bool $dryRun = false, ?callable $onUser = null): array
    {
        $summary = ['users_checked' => 0, 'users_with_drift' => 0, 'bytes_affected' => 0, 'reserved_bytes_affected' => 0];
        User::withTrashed()->select('id')->chunkById(200, function ($users) use ($dryRun, $onUser, &$summary) {
            foreach ($users as $user) {
                $result = $this->reconcileUser($user, $dryRun);
                ++$summary['users_checked'];
                $summary['users_with_drift'] += (int) $result['changed'];
                $summary['bytes_affected'] += abs($result['difference_bytes']);
                $summary['reserved_bytes_affected'] += abs($result['difference_reserved_bytes']);
                if ($onUser) { $onUser($result); }
            }
        });
        return $summary;
    }

    public function releaseUploadReservation(string $reservationId): bool
    {
        return $this->usage->release($reservationId);
    }

    public function releaseExpiredUploadReservations(int $limit = 1000, ?int $userId = null, bool $dryRun = false): array
    {
        $query = DB::table('storage_upload_reservations')->where('status', 'reserved')->where('expires_at', '<=', now());
        if ($userId !== null) { $query->where('user_id', $userId); }
        $rows = $query->orderBy('id')->limit($limit)->get();
        $released = 0;
        if (!$dryRun) {
            foreach ($rows as $row) { $released += (int) $this->usage->release($row->reservation_id, true); }
        }
        return ['processed' => $rows->count(), 'released' => $released];
    }

    private function id(User|int $user): int { return $user instanceof User ? (int) $user->id : $user; }
}
