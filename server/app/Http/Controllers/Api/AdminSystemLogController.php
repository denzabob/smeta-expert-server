<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminSystemLogController extends Controller
{
    private const TYPE_LARAVEL = 'laravel';
    private const TYPE_FRONTEND = 'frontend';
    private const DEFAULT_LINES = 500;
    private const MIN_LINES = 100;
    private const MAX_LINES = 1000;

    /**
     * GET /api/admin/system/logs
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $type = $this->normalizeType((string) $request->query('type', self::TYPE_LARAVEL));
        $lines = $this->normalizeLines($request->query('lines'));
        $level = $this->normalizeLevel((string) $request->query('level', ''));
        $fileName = (string) $request->query('file', '');

        $availableFiles = $this->listAvailableFiles($type);
        $publicFiles = array_map(static fn (array $file): array => [
            'name' => $file['name'],
            'size' => $file['size'],
            'modified_at' => $file['modified_at'],
            'exists' => $file['exists'],
        ], $availableFiles);
        $resolvedFile = $this->resolveFilePath($type, $fileName, $availableFiles);

        $rawText = '';
        $entries = [];
        $error = null;
        $fileSize = 0;
        $updatedAt = null;
        $lastEntryAt = null;

        if ($resolvedFile !== null && File::exists($resolvedFile['path'])) {
            $fileSize = (int) (File::size($resolvedFile['path']) ?? 0);
            $lastModified = File::lastModified($resolvedFile['path']);
            $updatedAt = $lastModified ? date(DATE_ATOM, $lastModified) : null;

            $rawText = $this->tailFile($resolvedFile['path'], $lines);
            $entries = $type === self::TYPE_LARAVEL
                ? $this->parseLaravelEntries($rawText)
                : $this->parseFrontendEntries($rawText);

            if ($level !== null) {
                $entries = array_values(array_filter(
                    $entries,
                    fn (array $entry): bool => $this->canonicalLevel((string) ($entry['level'] ?? '')) === $level
                ));
            }

            if (!empty($entries)) {
                for ($i = count($entries) - 1; $i >= 0; $i--) {
                    if (!empty($entries[$i]['timestamp'])) {
                        $lastEntryAt = (string) $entries[$i]['timestamp'];
                        break;
                    }
                }
            }
        } else {
            $error = 'Log file not found.';
        }

        return response()->json([
            'type' => $type,
            'file' => $resolvedFile['name'] ?? null,
            'lines' => $lines,
            'level' => $level,
            'available_files' => $publicFiles,
            'file_size' => $fileSize,
            'updated_at' => $updatedAt,
            'last_entry_at' => $lastEntryAt,
            'entries' => $entries,
            'raw_text' => $rawText,
            'error' => $error,
        ]);
    }

    /**
     * GET /api/admin/system/logs/download
     */
    public function download(Request $request): BinaryFileResponse
    {
        $this->authorizeAdmin($request);

        $type = $this->normalizeType((string) $request->query('type', self::TYPE_LARAVEL));
        $fileName = (string) $request->query('file', '');
        $availableFiles = $this->listAvailableFiles($type);
        $resolvedFile = $this->resolveFilePath($type, $fileName, $availableFiles);

        if ($resolvedFile === null || !File::exists($resolvedFile['path'])) {
            abort(404, 'Log file not found.');
        }

        if ($request->boolean('inline')) {
            return response()->file(
                $resolvedFile['path'],
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return response()->download(
            $resolvedFile['path'],
            $resolvedFile['name'],
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $role = strtolower(trim((string) ($user->role ?? $user->user_role ?? '')));
        $isRoleAdmin = in_array($role, ['admin', 'superadmin'], true);

        if (!$user || (!$isRoleAdmin && (int) $user->id !== 1)) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    private function normalizeType(string $type): string
    {
        $normalized = strtolower(trim($type));

        if (!in_array($normalized, [self::TYPE_LARAVEL, self::TYPE_FRONTEND], true)) {
            return self::TYPE_LARAVEL;
        }

        return $normalized;
    }

    private function normalizeLines(mixed $lines): int
    {
        $value = (int) $lines;

        if ($value < self::MIN_LINES) {
            return self::DEFAULT_LINES;
        }

        if ($value > self::MAX_LINES) {
            return self::MAX_LINES;
        }

        return $value;
    }

    private function normalizeLevel(string $level): ?string
    {
        $normalized = strtoupper(trim($level));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'ERROR', 'WARNING', 'INFO' => $normalized,
            default => null,
        };
    }

    private function canonicalLevel(string $level): string
    {
        $normalized = strtoupper(trim($level));

        if (
            str_contains($normalized, 'ERROR') ||
            str_contains($normalized, 'CRITICAL') ||
            str_contains($normalized, 'FATAL') ||
            str_contains($normalized, 'ALERT') ||
            str_contains($normalized, 'EMERGENCY')
        ) {
            return 'ERROR';
        }

        if (str_contains($normalized, 'WARN')) {
            return 'WARNING';
        }

        return 'INFO';
    }

    /**
     * @return array<int, array{name: string, path: string, size: int, modified_at: string|null, exists: bool}>
     */
    private function listAvailableFiles(string $type): array
    {
        $storageLogsPath = storage_path('logs');
        $result = [];

        if ($type === self::TYPE_LARAVEL) {
            $files = File::glob($storageLogsPath . DIRECTORY_SEPARATOR . '*.log') ?: [];
        } else {
            $files = File::glob($storageLogsPath . DIRECTORY_SEPARATOR . 'frontend*.log') ?: [];
            $defaultFrontendPath = $storageLogsPath . DIRECTORY_SEPARATOR . 'frontend.log';
            if (empty($files)) {
                $files = [$defaultFrontendPath];
            } elseif (!in_array($defaultFrontendPath, $files, true)) {
                $files[] = $defaultFrontendPath;
            }
        }

        usort($files, static function (string $a, string $b): int {
            $aTime = File::exists($a) ? (int) (File::lastModified($a) ?? 0) : 0;
            $bTime = File::exists($b) ? (int) (File::lastModified($b) ?? 0) : 0;
            return $bTime <=> $aTime;
        });

        foreach ($files as $path) {
            $exists = File::exists($path);
            $result[] = [
                'name' => basename($path),
                'path' => $path,
                'size' => $exists ? (int) (File::size($path) ?? 0) : 0,
                'modified_at' => $exists ? date(DATE_ATOM, (int) (File::lastModified($path) ?? time())) : null,
                'exists' => $exists,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{name: string, path: string, size: int, modified_at: string|null, exists: bool}> $availableFiles
     * @return array{name: string, path: string}|null
     */
    private function resolveFilePath(string $type, string $fileName, array $availableFiles): ?array
    {
        $safeName = basename(trim($fileName));
        if ($safeName !== '' && str_ends_with($safeName, '.log')) {
            foreach ($availableFiles as $file) {
                if ($file['name'] === $safeName) {
                    return ['name' => $file['name'], 'path' => $file['path']];
                }
            }
        }

        $defaultName = $type === self::TYPE_LARAVEL ? 'laravel.log' : 'frontend.log';
        foreach ($availableFiles as $file) {
            if ($file['name'] === $defaultName) {
                return ['name' => $file['name'], 'path' => $file['path']];
            }
        }

        if (!empty($availableFiles)) {
            return ['name' => $availableFiles[0]['name'], 'path' => $availableFiles[0]['path']];
        }

        return null;
    }

    private function tailFile(string $path, int $lines): string
    {
        if (!is_readable($path) || $lines <= 0) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $buffer = '';
        $chunkSize = 4096;

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        if ($position === false || $position <= 0) {
            fclose($handle);
            return '';
        }

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;

            fseek($handle, $position);
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
        }

        fclose($handle);

        $rows = preg_split('/\r\n|\n|\r/', $buffer) ?: [];
        if (count($rows) > $lines) {
            $rows = array_slice($rows, -$lines);
        }

        return implode("\n", $rows);
    }

    /**
     * @return array<int, array{timestamp: string|null, level: string, message: string, stack_trace: string|null}>
     */
    private function parseLaravelEntries(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+(?:[A-Za-z0-9_.-]+\.)?([A-Za-z]+):\s?(.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'timestamp' => trim($matches[1]),
                    'level' => $this->canonicalLevel((string) $matches[2]),
                    'message' => trim($matches[3]),
                    'stack_trace' => null,
                ];

                continue;
            }

            if ($current === null) {
                if (trim($line) === '') {
                    continue;
                }

                $entries[] = [
                    'timestamp' => null,
                    'level' => $this->canonicalLevel('INFO'),
                    'message' => $line,
                    'stack_trace' => null,
                ];
                continue;
            }

            $stack = (string) ($current['stack_trace'] ?? '');
            $current['stack_trace'] = trim($stack . ($stack !== '' ? "\n" : '') . $line) ?: null;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @return array<int, array{timestamp: string|null, level: string, message: string, stack_trace: string|null}>
     */
    private function parseFrontendEntries(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[([^\]]+)\]\s+([A-Za-z]+):\s?(.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'timestamp' => trim($matches[1]),
                    'level' => $this->canonicalLevel((string) $matches[2]),
                    'message' => trim($matches[3]),
                    'stack_trace' => null,
                ];

                continue;
            }

            if (preg_match('/^([A-Za-z]+):\s?(.*)$/', $line, $matches) === 1 && !str_starts_with($line, ' ')) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'timestamp' => null,
                    'level' => $this->canonicalLevel((string) $matches[1]),
                    'message' => trim($matches[2]),
                    'stack_trace' => null,
                ];

                continue;
            }

            if ($current === null) {
                if (trim($line) === '') {
                    continue;
                }

                $entries[] = [
                    'timestamp' => null,
                    'level' => $this->canonicalLevel('INFO'),
                    'message' => $line,
                    'stack_trace' => null,
                ];
                continue;
            }

            $stack = (string) ($current['stack_trace'] ?? '');
            $current['stack_trace'] = trim($stack . ($stack !== '' ? "\n" : '') . $line) ?: null;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }
}
