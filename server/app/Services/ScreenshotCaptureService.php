<?php

namespace App\Services;

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

        $process = new Process($command, base_path(), [
            'PYTHONPATH' => base_path(),
        ]);
        $process->setTimeout(90);
        $process->run();

        if (!$process->isSuccessful()) {
            return [
                'status' => 'error',
                'screenshot_path' => null,
                'meta' => [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 1000),
                ],
            ];
        }

        $raw = trim($process->getOutput());
        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return [
                'status' => 'error',
                'screenshot_path' => null,
                'meta' => [
                    'stdout' => mb_substr($raw, 0, 1000),
                ],
            ];
        }

        return [
            'status' => $parsed['status'] ?? 'error',
            'screenshot_path' => $parsed['screenshot_path'] ?? null,
            'meta' => $parsed['meta'] ?? [],
        ];
    }
}
