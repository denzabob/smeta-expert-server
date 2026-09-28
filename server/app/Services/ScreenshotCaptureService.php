<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\Storage\ObjectStorage;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ScreenshotCaptureService
{
    public function captureByUrl(
        string $url,
        float $price,
        string $currency = 'RUB',
        ?int $regionId = null,
        ?int $materialId = null,
        ?int $revisionRunItemId = null,
        ?int $ownerId = null,
    ): array {
        $startedAt = microtime(true);
        $maxParallel = max(1, (int) config('parser.screenshot_max_parallel', 3));
        $slotWaitSeconds = max(1, (int) config('parser.screenshot_slot_wait_seconds', 30));
        $processTimeoutSeconds = max(5, (int) config('parser.screenshot_process_timeout_seconds', 30));

        Log::info('screenshot.start', [
            'url' => $url,
            'revision_run_item_id' => $revisionRunItemId,
            'material_id' => $materialId,
            'region_id' => $regionId,
        ]);

        $slot = $this->acquireSlotLock($maxParallel, $slotWaitSeconds);
        if (!$slot) {
            $result = [
                'status' => 'blocked',
                'screenshot_path' => null,
                'meta' => [
                    'reason' => 'parallel_limit_reached',
                    'max_parallel' => $maxParallel,
                    'wait_seconds' => $slotWaitSeconds,
                ],
            ];

            Log::error('screenshot.failed', [
                'url' => $url,
                'status' => $result['status'],
                'meta' => $result['meta'],
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return $result;
        }

        $captureDirectory = null;
        try {
            $captureDirectory = $this->createTemporaryCaptureDirectory();
            $pythonPath = (string) config('parser.python_path', 'python3');
            $scriptPath = base_path('parser/screenshot_by_url.py');

            $command = [
                $pythonPath,
                $scriptPath,
                '--url', $url,
                '--price', (string) $price,
                '--currency', $currency,
                '--region-id', (string) ($regionId ?? 0),
                '--output-dir', $captureDirectory,
            ];

            if ($materialId) {
                $command[] = '--material-id';
                $command[] = (string) $materialId;
            }
            if ($revisionRunItemId) {
                $command[] = '--revision-run-item-id';
                $command[] = (string) $revisionRunItemId;
            }

            $process = new Process($command, base_path(), [
                'PYTHONPATH' => base_path(),
                'PLAYWRIGHT_BROWSERS_PATH' => '/root/.cache/ms-playwright',
                'SCREENSHOT_NAVIGATION_TIMEOUT_MS' => (string) config('parser.screenshot_navigation_timeout_ms', 20000),
                'SCREENSHOT_TOTAL_TIMEOUT_SECONDS' => (string) config('parser.screenshot_total_timeout_seconds', 45),
            ]);
            $process->setTimeout($processTimeoutSeconds);
            $process->run();

            if (!$process->isSuccessful()) {
                $status = $this->mapProcessFailureStatus($process);
                $result = [
                    'status' => $status,
                    'screenshot_path' => null,
                    'meta' => [
                        'exit_code' => $process->getExitCode(),
                        'stderr' => mb_substr($process->getErrorOutput(), 0, 1000),
                    ],
                ];

                Log::error('screenshot.failed', [
                    'url' => $url,
                    'status' => $status,
                    'meta' => $result['meta'],
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                return $result;
            }

            $raw = trim($process->getOutput());
            $parsed = json_decode($raw, true);

            if (!is_array($parsed)) {
                $result = [
                    'status' => 'error',
                    'screenshot_path' => null,
                    'meta' => [
                        'stdout' => mb_substr($raw, 0, 1000),
                    ],
                ];

                Log::error('screenshot.failed', [
                    'url' => $url,
                    'status' => $result['status'],
                    'meta' => $result['meta'],
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);

                return $result;
            }

            $status = (string) ($parsed['status'] ?? 'error');
            if ($status === 'timeout' || $status === 'navigation_error') {
                $status = 'blocked';
            }

            $meta = is_array($parsed['meta'] ?? null) ? $parsed['meta'] : [];
            if (!empty($meta['cloudflare_detected'])) {
                Log::info('screenshot.cloudflare_detected', [
                    'url' => $url,
                    'revision_run_item_id' => $revisionRunItemId,
                ]);
            }

            $result = [
                'status' => $status,
                'screenshot_path' => $this->resolveTemporaryCapturePath(
                    $captureDirectory,
                    $parsed['screenshot_path'] ?? null,
                ),
                'meta' => $meta,
            ];

            if ($result['status'] === 'ok' && !$result['screenshot_path']) {
                $result['status'] = 'error';
                $result['meta'] = ['reason' => 'screenshot_output_missing'];
            }

            if ($result['status'] === 'ok' && $result['screenshot_path']) {
                try {
                    $result['screenshot_path'] = $this->persistScreenshot((string) $result['screenshot_path'], $revisionRunItemId, $ownerId);
                } catch (\App\Services\Storage\StorageQuotaException $e) {
                    throw $e;
                } catch (\Throwable) {
                    $result['status'] = 'error';
                    $result['screenshot_path'] = null;
                    $result['meta'] = ['reason' => 'screenshot_storage_failed'];
                }
            }

            if ($result['status'] === 'ok' && $result['screenshot_path']) {
                Log::info('screenshot.saved', [
                    'url' => $url,
                    'disk' => 's1',
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);
            } else {
                Log::error('screenshot.failed', [
                    'url' => $url,
                    'status' => $result['status'],
                    'meta' => $result['meta'],
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                ]);
            }

            return $result;
        } finally {
            if ($captureDirectory !== null) {
                File::deleteDirectory($captureDirectory);
            }
            $this->releaseSlotLock($slot['handle']);
        }
    }

    private function createTemporaryCaptureDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smeta-screenshot-' . bin2hex(random_bytes(16));

        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException('Could not create temporary screenshot directory.');
        }

        return $path;
    }

    private function resolveTemporaryCapturePath(string $directory, mixed $relativePath): ?string
    {
        if (!is_string($relativePath) || $relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
        $candidate = realpath($directory . DIRECTORY_SEPARATOR . $normalized);
        $root = realpath($directory);

        if (!$candidate || !$root || !is_file($candidate)
            || !str_starts_with($candidate, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $candidate;
    }

    private function mapProcessFailureStatus(Process $process): string
    {
        $stderr = mb_strtolower($process->getErrorOutput());

        if ($process->isTimedOut()) {
            return 'blocked';
        }

        foreach (['cloudflare', 'just a moment', 'checking your browser', 'navigation', 'timeout'] as $needle) {
            if (str_contains($stderr, $needle)) {
                return 'blocked';
            }
        }

        return 'error';
    }

    private function persistScreenshot(string $sourcePath, ?int $revisionRunItemId, ?int $ownerId): string
    {
        if (is_file($sourcePath) && is_readable($sourcePath)) {
            try {
                $item = $revisionRunItemId ? \App\Models\RevisionRunItem::findOrFail($revisionRunItemId) : null;
                $projectOwner = $item?->run?->project?->user_id;
                if ($ownerId !== null && $projectOwner !== null && $ownerId !== (int) $projectOwner) {
                    throw new \LogicException('Screenshot account owner mismatch.');
                }
                $ownerId ??= $projectOwner ? (int) $projectOwner : null;
                if ($ownerId === null) {
                    // Global parser collection is a system source, not account-owned storage.
                    return app(ObjectStorage::class)->importFile('screenshots/parser', $sourcePath);
                }
                if ($item === null) {
                    throw new \LogicException('An account screenshot requires a business reference.');
                }
                $accountFiles = app(\App\Services\Storage\AccountFileStorage::class);
                $upload = $accountFiles->prepareFile('screenshots/captured', $sourcePath, $ownerId);
                return $accountFiles->commit([$upload], function () use ($upload, $item) {
                    $id = \Illuminate\Support\Facades\DB::table('storage_files')
                        ->where('disk', ObjectStorage::DISK)->where('path', $upload->path())->value('id');
                    app(\App\Services\Storage\StorageUsageService::class)->link((int) $id, 'smeta', $item->getTable(), (int) $item->id);
                    return $upload->path();
                });
            } finally {
                @unlink($sourcePath);
            }
        }

        throw new \RuntimeException('Screenshot output is missing.');
    }

    private function acquireSlotLock(int $maxParallel, int $waitSeconds): ?array
    {
        $lockDir = storage_path('framework/locks/screenshot_capture');
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            for ($slot = 1; $slot <= $maxParallel; $slot++) {
                $path = $lockDir . DIRECTORY_SEPARATOR . "slot_{$slot}.lock";
                $handle = @fopen($path, 'c+');
                if ($handle === false) {
                    continue;
                }

                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    return ['slot' => $slot, 'handle' => $handle];
                }

                @fclose($handle);
            }

            usleep(200000);
        }

        return null;
    }

    private function releaseSlotLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
