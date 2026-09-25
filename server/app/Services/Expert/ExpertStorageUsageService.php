<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OverflowException;
use InvalidArgumentException;
use LogicException;

final class ExpertStorageUsageService
{
    public function getUserUsage(User|int $user): ExpertStorageUsageSnapshot
    {
        $userId = $this->userId($user);
        $row = $this->usageRow($userId);

        // Existing accounts acquire a projection lazily from material rows.
        // This is a DB-only rebuild; displaying usage never contacts storage.
        return $row === null
            ? $this->recalculate($userId)
            : $this->snapshot($row);
    }

    public function increment(User|int $user, int $bytes): ExpertStorageUsageSnapshot
    {
        $this->assertNonNegative($bytes, 'bytes');
        $userId = $this->userId($user);

        return $this->atomically(function () use ($userId, $bytes): ExpertStorageUsageSnapshot {
            $row = $this->lockedUsageRow($userId, initializeFromMaterials: true);
            $nextBytes = $this->safeAdd((int) $row->originals_bytes, $bytes);
            $nextCount = $this->safeAdd((int) $row->materials_count, 1);

            DB::table('expert_storage_usages')->where('user_id', $userId)->update([
                'originals_bytes' => $nextBytes,
                'materials_count' => $nextCount,
                'updated_at' => now(),
            ]);

            return new ExpertStorageUsageSnapshot($nextBytes, null, null, null, $nextCount, (int) $row->reserved_bytes);
        });
    }

    /**
     * Lock the user's projection row for a quota check that will be followed by
     * a write in the same transaction.
     */
    public function lockUserUsage(User|int $user): ExpertStorageUsageSnapshot
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Locking storage usage requires an active transaction.');
        }

        $row = $this->lockedUsageRow($this->userId($user), initializeFromMaterials: true);

        return $this->snapshot($row);
    }

    public function decrement(User|int $user, int $bytes, int $materialsCount = 1): ExpertStorageUsageSnapshot
    {
        $this->assertNonNegative($bytes, 'bytes');
        $this->assertNonNegative($materialsCount, 'materialsCount');
        $userId = $this->userId($user);

        if ($bytes === 0 && $materialsCount === 0) {
            return $this->getUserUsage($userId);
        }

        return $this->atomically(function () use ($userId, $bytes, $materialsCount): ExpertStorageUsageSnapshot {
            $row = $this->lockedUsageRow($userId, initializeFromMaterials: true);
            $recordedBytes = (int) $row->originals_bytes;
            $recordedCount = (int) $row->materials_count;
            $underflow = $bytes > $recordedBytes || $materialsCount > $recordedCount;
            $nextBytes = max(0, $recordedBytes - $bytes);
            $nextCount = max(0, $recordedCount - $materialsCount);

            if ($underflow) {
                Log::warning('Expert storage usage decrement exceeded the recorded projection.', [
                    'user_id' => $userId,
                    'recorded_bytes' => $recordedBytes,
                    'requested_bytes' => $bytes,
                    'recorded_materials_count' => $recordedCount,
                    'requested_materials_count' => $materialsCount,
                    'error_code' => 'STORAGE_USAGE_UNDERFLOW',
                ]);
            }

            DB::table('expert_storage_usages')->where('user_id', $userId)->update([
                'originals_bytes' => $nextBytes,
                'materials_count' => $nextCount,
                'updated_at' => now(),
            ]);

            return new ExpertStorageUsageSnapshot($nextBytes, null, null, null, $nextCount, (int) $row->reserved_bytes);
        });
    }

    public function recalculate(User|int $user): ExpertStorageUsageSnapshot
    {
        $result = $this->reconcileUser($user);

        return new ExpertStorageUsageSnapshot(
            $result['calculated_bytes'],
            null,
            null,
            null,
            $result['calculated_materials_count'],
            $result['calculated_reserved_bytes'],
        );
    }

    /**
     * Rebuild all users in bounded batches. A dry run reads only and never
     * creates missing projection rows or changes timestamps.
     *
     * @param null|callable(array<string, int|bool>): void $onUser
     * @return array{users_checked: int, users_with_drift: int, bytes_affected: int}
     */
    public function recalculateAll(bool $dryRun = false, ?callable $onUser = null): array
    {
        $summary = [
            'users_checked' => 0,
            'users_with_drift' => 0,
            'bytes_affected' => 0,
            'reserved_bytes_affected' => 0,
        ];

        User::withTrashed()->select('id')->orderBy('id')->chunkById(200, function ($users) use (&$summary, $dryRun, $onUser): void {
            foreach ($users as $user) {
                $result = $this->reconcileUser((int) $user->id, $dryRun);
                ++$summary['users_checked'];

                if ($result['changed']) {
                    ++$summary['users_with_drift'];
                    $summary['bytes_affected'] += abs($result['difference_bytes']);
                    $summary['reserved_bytes_affected'] += abs($result['difference_reserved_bytes']);
                }

                if ($onUser !== null) {
                    $onUser($result);
                }
            }
        });

        return $summary;
    }

    /**
     * @return array{
     *   user_id: int,
     *   recorded_bytes: int,
     *   calculated_bytes: int,
     *   difference_bytes: int,
     *   recorded_materials_count: int,
     *   calculated_materials_count: int,
     *   difference_materials_count: int,
     *   recorded_reserved_bytes: int,
     *   calculated_reserved_bytes: int,
     *   difference_reserved_bytes: int,
     *   changed: bool,
     *   dry_run: bool
     * }
     */
    public function reconcileUser(User|int $user, bool $dryRun = false): array
    {
        $userId = $this->userId($user);

        $reconcile = function (bool $write) use ($userId, $dryRun): array {
            $row = $write
                ? $this->lockedUsageRow($userId, initializeFromMaterials: false)
                : $this->usageRow($userId);
            $recordedBytes = (int) ($row->originals_bytes ?? 0);
            $recordedCount = (int) ($row->materials_count ?? 0);
            $recordedReservedBytes = (int) ($row->reserved_bytes ?? 0);
            $calculated = $this->calculateFromMaterials($userId);
            $changed = $recordedBytes !== $calculated['bytes']
                || $recordedCount !== $calculated['count']
                || $recordedReservedBytes !== $calculated['reserved_bytes'];

            if ($write) {
                DB::table('expert_storage_usages')->where('user_id', $userId)->update([
                    'originals_bytes' => $calculated['bytes'],
                    'reserved_bytes' => $calculated['reserved_bytes'],
                    'materials_count' => $calculated['count'],
                    'updated_at' => now(),
                ]);
            }

            return [
                'user_id' => $userId,
                'recorded_bytes' => $recordedBytes,
                'calculated_bytes' => $calculated['bytes'],
                'difference_bytes' => $calculated['bytes'] - $recordedBytes,
                'recorded_materials_count' => $recordedCount,
                'calculated_materials_count' => $calculated['count'],
                'difference_materials_count' => $calculated['count'] - $recordedCount,
                'recorded_reserved_bytes' => $recordedReservedBytes,
                'calculated_reserved_bytes' => $calculated['reserved_bytes'],
                'difference_reserved_bytes' => $calculated['reserved_bytes'] - $recordedReservedBytes,
                'changed' => $changed,
                'dry_run' => $dryRun,
            ];
        };

        return $dryRun
            ? DB::transaction(fn (): array => $reconcile(false))
            : $this->atomically(fn (): array => $reconcile(true));
    }

    private function lockedUsageRow(int $userId, bool $initializeFromMaterials): object
    {
        $inserted = DB::table('expert_storage_usages')->insertOrIgnore([
            'user_id' => $userId,
            'originals_bytes' => 0,
            'reserved_bytes' => 0,
            'materials_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('expert_storage_usages')->where('user_id', $userId)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(User::class, [$userId]);
        }

        if ($inserted > 0 && $initializeFromMaterials) {
            $calculated = $this->calculateFromMaterials($userId);
            $row->originals_bytes = $calculated['bytes'];
            $row->reserved_bytes = $calculated['reserved_bytes'];
            $row->materials_count = $calculated['count'];
            DB::table('expert_storage_usages')->where('user_id', $userId)->update([
                'originals_bytes' => $calculated['bytes'],
                'reserved_bytes' => $calculated['reserved_bytes'],
                'materials_count' => $calculated['count'],
                'updated_at' => now(),
            ]);
        }

        return $row;
    }

    private function usageRow(int $userId): ?object
    {
        return DB::table('expert_storage_usages')->where('user_id', $userId)->first();
    }

    /** @return array{bytes: int, count: int, reserved_bytes: int} */
    private function calculateFromMaterials(int $userId): array
    {
        $totals = DB::table('expert_project_materials as materials')
            ->join('expert_projects as projects', 'projects.id', '=', 'materials.expert_project_id')
            ->where('projects.user_id', $userId)
            ->selectRaw('COALESCE(SUM(materials.size), 0) AS originals_bytes')
            ->selectRaw('COUNT(*) AS materials_count')
            ->first();

        $reservedBytes = (int) DB::table('expert_storage_upload_reservations')
            ->where('user_id', $userId)
            ->where('status', 'reserved')
            ->sum('requested_bytes');

        return [
            'bytes' => (int) ($totals->originals_bytes ?? 0),
            'count' => (int) ($totals->materials_count ?? 0),
            'reserved_bytes' => $reservedBytes,
        ];
    }

    private function snapshot(object $row): ExpertStorageUsageSnapshot
    {
        return new ExpertStorageUsageSnapshot(
            (int) $row->originals_bytes,
            null,
            null,
            null,
            (int) $row->materials_count,
            (int) ($row->reserved_bytes ?? 0),
        );
    }

    /** Reserve quota before touching S1. Must be called inside the quota-check transaction. */
    public function reserveUpload(User|int $user, int $projectId, string $reservationId, int $bytes, int $ttlMinutes): void
    {
        $this->assertNonNegative($bytes, 'bytes');
        if ($bytes < 1 || $projectId < 1 || preg_match('/^[0-9a-f-]{36}$/i', $reservationId) !== 1) {
            throw new InvalidArgumentException('A positive upload size, project id, and UUID reservation id are required.');
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Creating an upload reservation requires an active transaction.');
        }

        $userId = $this->userId($user);
        $row = $this->lockedUsageRow($userId, initializeFromMaterials: true);
        $nextReserved = $this->safeAdd((int) $row->reserved_bytes, $bytes);
        DB::table('expert_storage_usages')->where('user_id', $userId)->update([
            'reserved_bytes' => $nextReserved,
            'updated_at' => now(),
        ]);
        DB::table('expert_storage_upload_reservations')->insert([
            'reservation_id' => $reservationId,
            'user_id' => $userId,
            'expert_project_id' => $projectId,
            'requested_bytes' => $bytes,
            'status' => 'reserved',
            'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Consume a live reservation and book the completed material in one transaction. */
    public function consumeUploadReservation(User|int $user, string $reservationId, int $actualBytes): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Consuming an upload reservation requires an active transaction.');
        }

        $userId = $this->userId($user);
        $row = $this->lockedUsageRow($userId, initializeFromMaterials: false);
        $reservation = DB::table('expert_storage_upload_reservations')
            ->where('reservation_id', $reservationId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        if ($reservation === null || $reservation->status !== 'reserved'
            || now()->greaterThan($reservation->expires_at)
            || (int) $reservation->requested_bytes !== $actualBytes) {
            throw new LogicException('The Expert storage upload reservation is no longer valid.');
        }

        $recordedReserved = (int) $row->reserved_bytes;
        $reservedBytes = max(0, $recordedReserved - (int) $reservation->requested_bytes);
        if ($recordedReserved < (int) $reservation->requested_bytes) {
            Log::warning('Expert storage reservation accounting underflow during completion.', [
                'user_id' => $userId,
                'error_code' => 'STORAGE_RESERVATION_UNDERFLOW',
            ]);
        }

        DB::table('expert_storage_usages')->where('user_id', $userId)->update([
            'originals_bytes' => $this->safeAdd((int) $row->originals_bytes, $actualBytes),
            'reserved_bytes' => $reservedBytes,
            'materials_count' => $this->safeAdd((int) $row->materials_count, 1),
            'updated_at' => now(),
        ]);
        DB::table('expert_storage_upload_reservations')
            ->where('reservation_id', $reservationId)
            ->update(['status' => 'consumed', 'finished_at' => now(), 'updated_at' => now()]);
    }

    /** Idempotently release a failed or expired reservation. */
    public function releaseUploadReservation(string $reservationId): bool
    {
        return DB::transaction(function () use ($reservationId): bool {
            $identity = DB::table('expert_storage_upload_reservations')
                ->where('reservation_id', $reservationId)
                ->first(['user_id']);
            if ($identity === null) {
                return false;
            }

            $userId = (int) $identity->user_id;
            $row = $this->lockedUsageRow($userId, initializeFromMaterials: false);
            $reservation = DB::table('expert_storage_upload_reservations')
                ->where('reservation_id', $reservationId)
                ->lockForUpdate()
                ->first();
            if ($reservation === null || (int) $reservation->user_id !== $userId || $reservation->status !== 'reserved') {
                return false;
            }

            $recordedReserved = (int) $row->reserved_bytes;
            $requestedBytes = (int) $reservation->requested_bytes;
            $nextReserved = max(0, $recordedReserved - $requestedBytes);
            if ($recordedReserved < $requestedBytes) {
                Log::warning('Expert storage reservation accounting underflow during release.', [
                    'user_id' => $userId,
                    'error_code' => 'STORAGE_RESERVATION_UNDERFLOW',
                ]);
            }

            DB::table('expert_storage_usages')->where('user_id', $userId)->update([
                'reserved_bytes' => $nextReserved,
                'updated_at' => now(),
            ]);
            DB::table('expert_storage_upload_reservations')
                ->where('reservation_id', $reservationId)
                ->update(['status' => 'released', 'finished_at' => now(), 'updated_at' => now()]);

            return true;
        }, 3);
    }

    /** @return array{processed: int, released: int} */
    public function releaseExpiredUploadReservations(int $limit = 1000, ?int $userId = null, bool $dryRun = false): array
    {
        $reservationIds = DB::table('expert_storage_upload_reservations')
            ->where('status', 'reserved')
            ->where('expires_at', '<=', now())
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->limit(max(1, min(10000, $limit)))
            ->pluck('reservation_id');
        if ($dryRun) {
            return ['processed' => $reservationIds->count(), 'released' => 0];
        }

        $released = 0;
        foreach ($reservationIds as $reservationId) {
            if ($this->releaseUploadReservation((string) $reservationId)) {
                ++$released;
            }
        }

        return ['processed' => $reservationIds->count(), 'released' => $released];
    }

    private function userId(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->getKey() : $user;
        if ($userId < 1) {
            throw new InvalidArgumentException('A persisted user id is required for storage accounting.');
        }

        return $userId;
    }

    private function assertNonNegative(int $value, string $name): void
    {
        if ($value < 0) {
            throw new InvalidArgumentException("{$name} must not be negative.");
        }
    }

    private function safeAdd(int $current, int $delta): int
    {
        if ($delta > PHP_INT_MAX - $current) {
            throw new OverflowException('Expert storage usage exceeds the supported integer range.');
        }

        return $current + $delta;
    }

    private function atomically(callable $callback): mixed
    {
        return DB::transactionLevel() > 0
            ? $callback()
            : DB::transaction($callback, 3);
    }
}
