<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertAiRun;
use App\Models\Expert\ExpertAiRunEvent;
use Illuminate\Support\Facades\DB;

final class ExpertAiRunEventRecorder
{
    /**
     * Append one event and allocate the run-wide sequence while holding the
     * parent run row lock. Lifecycle and SSE activity events share this path.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $runId,
        string $source,
        string $level,
        string $eventCode,
        string $status,
        array $payload = [],
    ): int {
        return DB::transaction(function () use ($runId, $source, $level, $eventCode, $status, $payload): int {
            $run = ExpertAiRun::query()->where('run_id', $runId)->lockForUpdate()->firstOrFail();
            $lastSequence = ExpertAiRunEvent::query()
                ->where('expert_ai_run_id', $run->id)
                ->max('seq');
            $sequence = (int) $lastSequence + 1;

            ExpertAiRunEvent::query()->create([
                'expert_ai_run_id' => (int) $run->id,
                'run_id' => $runId,
                'seq' => $sequence,
                'source' => $this->safeCode($source, 24) ?? 'unknown',
                'level' => in_array($level, ['debug', 'info', 'warning', 'error'], true) ? $level : 'info',
                'event_code' => $this->safeCode($eventCode, 100) ?? 'unknown',
                'stage' => $this->safeCode((string) $run->stage, 32) ?? 'unknown',
                'status' => $this->safeCode($status, 32) ?? 'unknown',
                'payload' => $this->safePayload($payload) ?: null,
                'created_at' => now(),
            ]);

            return $sequence;
        });
    }

    /** @param array<string, mixed> $payload @return array<string, bool|int|string> */
    private function safePayload(array $payload): array
    {
        $safe = [];
        foreach ([
            'material_count',
            'resolved_material_count',
            'active_material_count',
            'duration_ms',
        ] as $key) {
            if (is_int($payload[$key] ?? null) || is_numeric($payload[$key] ?? null)) {
                $safe[$key] = max(0, (int) $payload[$key]);
            }
        }

        foreach ([
            'execution_strategy' => 64,
            'requested_mode' => 32,
            'resolved_mode' => 32,
            'error_code' => 100,
            'last_activity_code' => 100,
        ] as $key => $maxLength) {
            if (is_string($payload[$key] ?? null)) {
                $value = $this->safeCode($payload[$key], $maxLength);
                if ($value !== null) {
                    $safe[$key] = $value;
                }
            }
        }

        foreach (['provider' => 64, 'model' => 255] as $key => $maxLength) {
            $pattern = $key === 'provider'
                ? '/^[a-z0-9_-]{1,'.$maxLength.'}$/i'
                : '/^[a-z0-9._\\/-]{1,'.$maxLength.'}$/i';
            if (is_string($payload[$key] ?? null) && preg_match($pattern, $payload[$key]) === 1) {
                $safe[$key] = $payload[$key];
            }
        }

        if (is_bool($payload['retryable'] ?? null)) {
            $safe['retryable'] = $payload['retryable'];
        }
        if (is_string($payload['exception_class'] ?? null)
            && preg_match('/^[A-Za-z0-9_\\\\]{1,255}$/', $payload['exception_class']) === 1) {
            $safe['exception_class'] = $payload['exception_class'];
        }

        return $safe;
    }

    private function safeCode(string $value, int $maxLength): ?string
    {
        return preg_match('/^[a-z0-9_.-]{1,'.$maxLength.'}$/i', $value) === 1 ? $value : null;
    }
}
