<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class StorageUsageService
{
    private const COUNTERS = ['used_bytes', 'files_count', 'images_bytes', 'images_count'];

    public function __construct(private readonly StorageQuotaService $quota) {}

    public function getUserUsage(int $userId): array
    {
        $row = DB::table('storage_usages')->where('user_id', $userId)->first();
        if (!$row) {
            return $this->recalculate($userId);
        }
        $snapshot = $this->values($row);
        $snapshot['reserved_bytes'] = (int) $row->reserved_bytes;
        $snapshot['modules'] = [];
        foreach (DB::table('storage_usage_modules')->where('user_id', $userId)->get() as $module) {
            $snapshot['modules'][$module->module] = $this->values($module);
        }
        return $snapshot;
    }

    public function lockUserUsage(int $userId): object
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Storage usage locking requires a transaction.');
        }
        $row = DB::table('storage_usages')->where('user_id', $userId)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }
        // Avoid duplicate INSERT shared locks before FOR UPDATE on existing owners.
        // The unique user key and reservation transaction retry cover concurrent lazy creation.
        DB::table('storage_usages')->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('storage_usages')->where('user_id', $userId)->lockForUpdate()->first();
    }

    public function reserve(int $userId, string $module, int $requestedBytes, ?string $reservationId = null, int $ttlMinutes = 60): string
    {
        $this->module($module);
        StorageQuotaService::add(0, $requestedBytes);
        if ($ttlMinutes < 1) {
            throw new InvalidArgumentException('Reservation TTL must be positive.');
        }
        return DB::transaction(function () use ($userId, $module, $requestedBytes, $reservationId, $ttlMinutes): string {
            $this->lockUserUsage($userId);
            $row = $this->project($userId);
            $id = $reservationId ?? (string) Str::uuid();
            $existing = DB::table('storage_upload_reservations')->where('reservation_id', $id)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->user_id !== $userId || $existing->module !== $module
                    || (int) $existing->requested_bytes !== $requestedBytes || $existing->status !== 'reserved'
                    || $existing->expires_at <= now()->toDateTimeString()) {
                    throw new RuntimeException('STORAGE_RESERVATION_CONFLICT');
                }
                return $id;
            }
            $this->quota->assertCanUpload($userId, $row['used_bytes'], $row['reserved_bytes'], $requestedBytes);
            DB::table('storage_upload_reservations')->insert([
                'reservation_id' => $id, 'user_id' => $userId, 'module' => $module,
                'requested_bytes' => $requestedBytes, 'status' => 'reserved',
                'expires_at' => now()->addMinutes($ttlMinutes), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->project($userId);
            return $id;
        }, attempts: 3);
    }

    public function finalize(string $reservationId, array $metadata, array $links = []): int
    {
        return DB::transaction(function () use ($reservationId, $metadata, $links): int {
            $reservation = DB::table('storage_upload_reservations')->where('reservation_id', $reservationId)->first();
            if (!$reservation) {
                throw new RuntimeException('STORAGE_RESERVATION_NOT_FOUND');
            }
            $this->lockUserUsage((int) $reservation->user_id);
            $reservation = DB::table('storage_upload_reservations')->where('id', $reservation->id)->lockForUpdate()->first();
            $data = $this->metadata($metadata);
            if ($reservation->status === 'consumed') {
                $file = DB::table('storage_files')->where('disk', $data['disk'])->where('path', $data['path'])->lockForUpdate()->first();
                $this->assertSameFile($file, (int) $reservation->user_id, $reservation->module, $data);
                if ((int) $reservation->actual_bytes !== $data['size_bytes'] || (int) $reservation->storage_file_id !== (int) $file->id) {
                    throw new RuntimeException('STORAGE_RESERVATION_CONFLICT');
                }
                $this->addLinks((int) $file->id, $reservation->module, $links);
                return (int) $file->id;
            }
            if ($reservation->status !== 'reserved' || $reservation->expires_at <= now()->toDateTimeString()) {
                throw new RuntimeException('STORAGE_RESERVATION_INACTIVE');
            }
            $usage = $this->project((int) $reservation->user_id);
            if ($data['billable']) {
                $this->quota->assertCanUpload((int) $reservation->user_id, $usage['used_bytes'],
                    $usage['reserved_bytes'] - (int) $reservation->requested_bytes, $data['size_bytes']);
            }
            $id = $this->register((int) $reservation->user_id, $reservation->module, $data, $links);
            DB::table('storage_upload_reservations')->where('id', $reservation->id)->update([
                'status' => 'consumed', 'storage_file_id' => $id, 'actual_bytes' => $data['size_bytes'], 'finished_at' => now(), 'updated_at' => now(),
            ]);
            $this->project((int) $reservation->user_id);
            return $id;
        });
    }

    /** Verified physical metadata only; backfill deliberately bypasses upload quota. */
    public function register(int $userId, string $module, array $metadata, array $links = []): int
    {
        $this->module($module);
        $data = $this->metadata($metadata);
        return DB::transaction(function () use ($userId, $module, $data, $links): int {
            $this->lockUserUsage($userId);
            $file = DB::table('storage_files')->where('disk', $data['disk'])->where('path', $data['path'])->lockForUpdate()->first();
            if ($file) {
                $this->assertSameFile($file, $userId, $module, $data);
                $id = (int) $file->id;
            } else {
                $id = DB::table('storage_files')->insertGetId($data + [
                    'public_id' => (string) Str::uuid(), 'user_id' => $userId, 'module' => $module,
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->addLinks($id, $module, $links);
            $this->project($userId);
            return $id;
        });
    }

    public function link(int $fileId, string $module, string $sourceType, int|string $sourceId): void
    {
        $this->module($module);
        if ((is_int($sourceId) && $sourceId < 1) || (string) $sourceId === '' || strlen((string) $sourceId) > 64
            || $sourceType === '' || strlen($sourceType) > 100) {
            throw new InvalidArgumentException('Invalid storage business reference.');
        }
        DB::transaction(function () use ($fileId, $module, $sourceType, $sourceId): void {
            $file = DB::table('storage_files')->where('id', $fileId)->first();
            if (!$file) {
                throw new RuntimeException('STORAGE_FILE_NOT_FOUND');
            }
            $this->lockUserUsage((int) $file->user_id);
            $file = DB::table('storage_files')->where('id', $fileId)->lockForUpdate()->first();
            if ($file->status !== 'active' || $file->deleted_at !== null) {
                throw new RuntimeException('STORAGE_FILE_INACTIVE');
            }
            DB::table('storage_file_links')->insertOrIgnore([
                'storage_file_id' => $fileId, 'module' => $module, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'created_at' => now(),
            ]);
        });
    }

    public function unlink(string $module, string $sourceType, int|string $sourceId, ?int $storageFileId = null): array
    {
        $this->module($module);
        if ((is_int($sourceId) && $sourceId < 1) || (string) $sourceId === '' || strlen((string) $sourceId) > 64
            || $sourceType === '' || strlen($sourceType) > 100) {
            throw new InvalidArgumentException('Invalid storage business reference.');
        }
        return DB::transaction(function () use ($module, $sourceType, $sourceId, $storageFileId): array {
            $query = DB::table('storage_file_links')->where('module', $module)->where('source_type', $sourceType)->where('source_id', $sourceId);
            if ($storageFileId !== null) {
                $query->where('storage_file_id', $storageFileId);
            }
            $ids = (clone $query)->pluck('storage_file_id');
            $files = DB::table('storage_files')->whereIn('id', $ids)->orderBy('user_id')->orderBy('id')->get();
            foreach ($files->pluck('user_id')->unique() as $userId) {
                $this->lockUserUsage((int) $userId);
            }
            $currentIds = (clone $query)->lockForUpdate()->pluck('storage_file_id');
            // A new owner cannot safely be locked after registry/link locks have been acquired.
            // Refuse rather than delete a reference outside the owner locks discovered initially.
            $ownerIds = $files->pluck('user_id')->unique();
            if (DB::table('storage_files')->whereIn('id', $currentIds)->whereNotIn('user_id', $ownerIds)->lockForUpdate()->first() !== null) {
                throw new RuntimeException('STORAGE_REFERENCE_CHANGED_RETRY');
            }
            $ids = $currentIds;
            $files = DB::table('storage_files')->whereIn('id', $ids)->orderBy('user_id')->orderBy('id')->lockForUpdate()->get();
            (clone $query)->whereIn('storage_file_id', $ids)->delete();
            $removed = [];
            foreach ($files as $file) {
                if ($file->status === 'active' && DB::table('storage_file_links')->where('storage_file_id', $file->id)->lockForUpdate()->first() === null) {
                    DB::table('storage_files')->where('id', $file->id)->update(['status' => 'deleting', 'deleted_at' => now(), 'updated_at' => now()]);
                    $removed[] = (array) $file;
                }
            }
            foreach ($files->pluck('user_id')->unique() as $userId) {
                $this->project((int) $userId);
            }
            return $removed;
        });
    }

    public function release(string $reservationId, bool $expired = false): bool
    {
        return DB::transaction(function () use ($reservationId, $expired): bool {
            $row = DB::table('storage_upload_reservations')->where('reservation_id', $reservationId)->first();
            if (!$row) {
                return false;
            }
            $this->lockUserUsage((int) $row->user_id);
            $row = DB::table('storage_upload_reservations')->where('id', $row->id)->lockForUpdate()->first();
            if ($row->status !== 'reserved' || ($expired && $row->expires_at > now()->toDateTimeString())) {
                return false;
            }
            DB::table('storage_upload_reservations')->where('id', $row->id)->update([
                'status' => $expired ? 'expired' : 'released', 'finished_at' => now(), 'updated_at' => now(),
            ]);
            $this->project((int) $row->user_id);
            return true;
        });
    }

    public function expireReservations(): int
    {
        $count = 0;
        DB::table('storage_upload_reservations')->where('status', 'reserved')->where('expires_at', '<=', now())
            ->orderBy('id')->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $count += (int) $this->release($row->reservation_id, true);
                }
            });
        return $count;
    }

    public function recalculate(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $this->lockUserUsage($userId);
            $this->project($userId);
            return $this->getUserUsage($userId);
        });
    }

    public function audit(int $userId): array
    {
        $expected = $this->calculate($userId);
        $row = DB::table('storage_usages')->where('user_id', $userId)->first();
        $actual = $row ? $this->values($row) + ['reserved_bytes' => (int) $row->reserved_bytes]
            : array_fill_keys(array_merge(self::COUNTERS, ['reserved_bytes']), 0);
        $modules = [];
        foreach (DB::table('storage_usage_modules')->where('user_id', $userId)->get() as $module) {
            $modules[$module->module] = $this->values($module);
        }
        $mismatches = [];
        foreach (array_merge(self::COUNTERS, ['reserved_bytes']) as $key) {
            if ($actual === null || $actual[$key] !== $expected[$key]) {
                $mismatches[$key] = ['stored' => $actual[$key] ?? null, 'calculated' => $expected[$key]];
            }
        }
        foreach (array_unique(array_merge(array_keys($modules), array_keys($expected['modules']))) as $module) {
            $zero = array_fill_keys(self::COUNTERS, 0);
            if (($modules[$module] ?? $zero) !== ($expected['modules'][$module] ?? $zero)) {
                $mismatches['module:'.$module] = ['stored' => $modules[$module] ?? $zero, 'calculated' => $expected['modules'][$module] ?? $zero];
            }
        }
        $duplicates = DB::table('storage_files')->where('user_id', $userId)->select('disk', 'path')->groupBy('disk', 'path')->havingRaw('COUNT(*) > 1')->get()->count();
        return ['user_id' => $userId, 'mismatches' => $mismatches, 'duplicate_objects' => $duplicates,
            'calculated' => $expected, 'recorded' => $actual === null ? null : $actual + ['modules' => $modules]];
    }

    private function project(int $userId): array
    {
        $snapshot = $this->calculate($userId, currentRead: true);
        $values = array_intersect_key($snapshot, array_flip(array_merge(self::COUNTERS, ['reserved_bytes'])));
        DB::table('storage_usages')->where('user_id', $userId)->update($values + ['updated_at' => now()]);
        foreach (['expert', 'smeta'] as $module) {
            DB::table('storage_usage_modules')->updateOrInsert(['user_id' => $userId, 'module' => $module],
                ($snapshot['modules'][$module] ?? array_fill_keys(self::COUNTERS, 0)) + ['created_at' => now(), 'updated_at' => now()]);
        }
        return $snapshot;
    }

    private function calculate(int $userId, bool $currentRead = false): array
    {
        $snapshot = array_fill_keys(self::COUNTERS, 0) + ['reserved_bytes' => 0, 'modules' => []];
        $filesQuery = DB::table('storage_files')->where('user_id', $userId)->where('billable', true)->where('status', 'active')->whereNull('deleted_at')
            ->select('module', 'category', 'size_bytes');
        if ($currentRead) {
            $filesQuery->lockForUpdate();
        }
        $rows = $filesQuery->get();
        foreach ($rows as $row) {
            $module = $snapshot['modules'][$row->module] ?? array_fill_keys(self::COUNTERS, 0);
            foreach (['used_bytes' => (int) $row->size_bytes, 'files_count' => 1,
                'images_bytes' => $row->category === 'image' ? (int) $row->size_bytes : 0,
                'images_count' => $row->category === 'image' ? 1 : 0] as $key => $value) {
                $snapshot[$key] = StorageQuotaService::add($snapshot[$key], $value);
                $module[$key] = StorageQuotaService::add($module[$key], $value);
            }
            $snapshot['modules'][$row->module] = $module;
        }
        $reservationsQuery = DB::table('storage_upload_reservations')->where('user_id', $userId)->where('status', 'reserved')
            ->where('expires_at', '>', now())->select('requested_bytes');
        if ($currentRead) {
            $reservationsQuery->lockForUpdate();
        }
        foreach ($reservationsQuery->get() as $row) {
            $snapshot['reserved_bytes'] = StorageQuotaService::add($snapshot['reserved_bytes'], (int) $row->requested_bytes);
        }
        return $snapshot;
    }

    private function metadata(array $metadata): array
    {
        $bytes = $metadata['size_bytes'] ?? null;
        if (!is_int($bytes)) {
            throw new InvalidArgumentException('Verified integer size_bytes is required.');
        }
        StorageQuotaService::add(0, $bytes);
        $disk = $metadata['disk'] ?? '';
        $path = $metadata['path'] ?? '';
        $purpose = $metadata['purpose'] ?? '';
        if (!is_string($disk) || $disk === '' || strlen($disk) > 32 || !is_string($path) || $path === ''
            || mb_strlen($path) > 512 || !is_string($purpose) || $purpose === '' || strlen($purpose) > 100) {
            throw new InvalidArgumentException('Invalid storage object metadata.');
        }
        $mime = $metadata['mime_type'] ?? null;
        return ['disk' => $disk, 'path' => $path, 'purpose' => $purpose, 'size_bytes' => $bytes,
            'mime_type' => $mime, 'original_filename' => $metadata['original_filename'] ?? null,
            'category' => is_string($mime) && str_starts_with(strtolower($mime), 'image/') ? 'image' : 'file',
            'billable' => (bool) ($metadata['billable'] ?? true)];
    }

    private function assertSameFile(?object $file, int $userId, string $module, array $data): void
    {
        if (!$file || (int) $file->user_id !== $userId || $file->module !== $module || (int) $file->size_bytes !== $data['size_bytes']
            || (bool) $file->billable !== $data['billable'] || $file->category !== $data['category']
            || $file->status !== 'active' || $file->deleted_at !== null) {
            throw new RuntimeException('STORAGE_OBJECT_METADATA_CONFLICT');
        }
    }

    private function addLinks(int $fileId, string $module, array $links): void
    {
        foreach ($links as $link) {
            $this->link($fileId, $link['module'] ?? $module, $link['source_type'], $link['source_id']);
        }
    }

    private function module(string $module): void
    {
        if (!in_array($module, ['expert', 'smeta'], true)) {
            throw new InvalidArgumentException('Unsupported account storage module.');
        }
    }

    private function values(object $row): array
    {
        $result = [];
        foreach (self::COUNTERS as $key) {
            $result[$key] = (int) $row->$key;
        }
        return $result;
    }
}
