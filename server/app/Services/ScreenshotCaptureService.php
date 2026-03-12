<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ScreenshotCaptureService
{
    public function captureByUrl(
        string $url,
        float $price,
        string $currency = 'RUB',
        ?int $regionId = null,
        ?int $materialId = null,
        ?int $revisionRunItemId = null
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

        $pythonPath = (string) config('parser.python_path', 'python3');
        $scriptPath = base_path('parser/screenshot_by_url.py');

        $command = [
            $pythonPath,
            $scriptPath,
            '--url', $url,
            '--price', (string) $price,
            '--currency', $currency,
            '--region-id', (string) ($regionId ?? 0),
        ];

        if ($materialId) {
            $command[] = '--material-id';
            $command[] = (string) $materialId;
        }
        if ($revisionRunItemId) {
            $command[] = '--revision-run-item-id';
            $command[] = (string) $revisionRunItemId;
        }

        try {
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
                'screenshot_path' => $parsed['screenshot_path'] ?? null,
                'meta' => $meta,
            ];

            if ($result['status'] === 'ok' && $result['screenshot_path']) {
                Log::info('screenshot.saved', [
                    'url' => $url,
                    'screenshot_path' => $result['screenshot_path'],
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
            $this->releaseSlotLock($slot['handle']);
        }
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
