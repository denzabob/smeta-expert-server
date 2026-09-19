<?php

declare(strict_types=1);

namespace App\Services\Expert;

use Illuminate\Support\Str;

/**
 * Serialises a redacted, high-level operational trace to the existing SSE
 * transport. It is deliberately independent from material and provider code.
 */
final class SseExpertRunActivitySink implements ExpertRunActivitySink
{
    /** @var array<string, array{code: string, category: string, detail: ?string, started_at: float}> */
    private array $open = [];

    private int $sequence = 0;

    private ?string $lastActivityCode = null;

    /**
     * @param \Closure(string, array<string, mixed>): void $emit
     * @param \Closure(): bool $isCancellationRequested
     */
    public function __construct(
        private readonly string $runId,
        private readonly \Closure $emit,
        private readonly \Closure $isCancellationRequested,
    ) {}

    public function start(string $code, string $category, ?string $detail = null): string
    {
        $activityId = (string) Str::uuid();
        $activity = [
            'code' => $code,
            'category' => $category,
            'detail' => $this->safeDetail($detail),
            'started_at' => microtime(true),
        ];
        $this->open[$activityId] = $activity;
        $this->emitActivity($activityId, $activity, 'started');

        return $activityId;
    }

    public function complete(string $activityId, ?string $code = null): void
    {
        $activity = $this->open[$activityId] ?? null;
        if ($activity === null) {
            return;
        }

        unset($this->open[$activityId]);
        $activity['code'] = $code ?? $activity['code'];
        $this->emitActivity($activityId, $activity, 'completed');
    }

    public function skip(string $code, string $category, ?string $detail = null): string
    {
        return $this->recordWithStatus($code, $category, $detail, 'skipped');
    }

    public function fail(string $activityId, ?string $errorCode = null): void
    {
        $activity = $this->open[$activityId] ?? null;
        if ($activity === null) {
            return;
        }

        unset($this->open[$activityId]);
        $this->emitActivity($activityId, $activity, 'failed', $errorCode);
    }

    public function record(string $code, string $category, ?string $detail = null): string
    {
        return $this->recordWithStatus($code, $category, $detail, 'completed');
    }

    public function terminalize(string $status): void
    {
        $terminalStatus = in_array($status, ['failed', 'skipped'], true) ? $status : 'failed';
        foreach ($this->open as $activityId => $activity) {
            unset($this->open[$activityId]);
            $this->emitActivity($activityId, $activity, $terminalStatus);
        }
    }

    public function isCancellationRequested(): bool
    {
        return (bool) ($this->isCancellationRequested)();
    }

    public function lastActivityCode(): ?string
    {
        return $this->lastActivityCode;
    }

    private function recordWithStatus(string $code, string $category, ?string $detail, string $status): string
    {
        $activityId = (string) Str::uuid();
        $activity = [
            'code' => $code,
            'category' => $category,
            'detail' => $this->safeDetail($detail),
            'started_at' => microtime(true),
        ];
        $this->emitActivity($activityId, $activity, $status);

        return $activityId;
    }

    /** @param array{code: string, category: string, detail: ?string, started_at: float} $activity */
    private function emitActivity(string $activityId, array $activity, string $status, ?string $errorCode = null): void
    {
        $this->lastActivityCode = $activity['code'];
        $payload = [
            'version' => 1,
            'run_id' => $this->runId,
            'seq' => ++$this->sequence,
            'activity_id' => $activityId,
            'code' => $activity['code'],
            'status' => $status,
            'category' => $activity['category'],
        ];
        if ($activity['detail'] !== null) {
            $payload['detail'] = $activity['detail'];
        }
        ($this->emit)('activity', $payload);

    }

    private function safeDetail(?string $detail): ?string
    {
        if (! is_string($detail)) {
            return null;
        }

        $name = basename(str_replace('\\', '/', $detail));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? null : mb_substr($name, 0, 180, 'UTF-8');
    }
}
