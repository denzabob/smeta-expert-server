<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertAiRun;
use App\Models\Expert\ExpertConversation;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ExpertChatRunRegistry
{
    public function __construct(
        private readonly ExpertAiRunEventRecorder $eventRecorder,
    ) {}

    public function acquireConversation(ExpertConversation $conversation): Lock
    {
        // The lock must outlive every bounded generation, even when a lower
        // legacy lock TTL is configured. A live provider handshake cannot
        // silently lose exclusivity while the run still owns the connection.
        $lockSeconds = max(
            (int) config('expert.streaming.lock_seconds', 900),
            (int) config('expert.streaming.absolute_timeout_seconds', 600) + 60,
        );
        $lock = Cache::lock($this->conversationLockKey($conversation), $lockSeconds);

        if (! $lock->get()) {
            throw new ExpertChatRunInProgressException;
        }

        return $lock;
    }

    /** @return array{run_id:string,conversation_public_id:string,project_id:int,user_id:int,status:string,cancel_requested:bool,created_at:string,started_at:float,assistant_message_public_id:?string} */
    public function create(
        ExpertConversation $conversation,
        ?string $assistantMessagePublicId = null,
        ?float $startedAt = null,
        ?string $clientMessageId = null,
        string $requestedMode = ExpertModeResolution::AUTO,
        int $selectedMaterialCount = 0,
        ?string $runId = null,
        ?int $userMessageId = null,
    ): array {
        $runId ??= (string) Str::uuid();
        $startedAt ??= microtime(true);
        $createdAt = now();
        $run = [
            'run_id' => $runId,
            'conversation_public_id' => $conversation->public_id,
            'project_id' => (int) $conversation->project_id,
            'user_id' => (int) $conversation->project->user_id,
            'status' => 'starting',
            'cancel_requested' => false,
            'created_at' => $createdAt->toIso8601String(),
            'started_at' => $startedAt,
            'assistant_message_public_id' => $assistantMessagePublicId,
        ];

        ExpertAiRun::query()->create([
            'run_id' => $runId,
            'user_id' => $run['user_id'],
            'expert_project_id' => $run['project_id'],
            'expert_conversation_id' => (int) $conversation->id,
            'client_message_id' => $clientMessageId ?? (string) Str::uuid(),
            'user_message_id' => $userMessageId,
            'assistant_message_id' => null,
            'status' => 'running',
            'stage' => 'created',
            'requested_mode' => ExpertModeResolution::normalise($requestedMode),
            'selected_material_count' => max(0, $selectedMaterialCount),
            'persisted_material_count' => 0,
            'resolved_material_count' => 0,
            'active_material_count' => 0,
            'started_at' => $createdAt,
            'metadata' => [],
        ]);
        $this->eventRecorder->record(
            $runId,
            'lifecycle',
            'info',
            'run.created',
            'created',
            ['requested_mode' => ExpertModeResolution::normalise($requestedMode)],
        );

        Cache::put($this->runKey($run['run_id']), $run, now()->addSeconds($this->runTtlSeconds()));

        return $run;
    }

    /** @return array<string, mixed>|null */
    public function find(string $runId): ?array
    {
        $run = Cache::get($this->runKey($runId));

        return is_array($run) ? $run : null;
    }

    public function requestCancellation(string $runId): ?array
    {
        $lock = Cache::lock($this->runKey($runId).':cancel', 10);
        if (! $lock->get()) {
            return $this->find($runId);
        }

        try {
            $run = $this->find($runId);
            if ($run === null) {
                return null;
            }
            $run['cancel_requested'] = true;
            $run['status'] = in_array($run['status'] ?? null, ['completed', 'cancelled', 'interrupted', 'failed'], true)
                ? $run['status']
                : 'stopping';
            Cache::put($this->runKey($runId), $run, now()->addSeconds($this->runTtlSeconds()));

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function isCancellationRequested(string $runId): bool
    {
        return (bool) ($this->find($runId)['cancel_requested'] ?? false);
    }

    public function currentStage(string $runId): ?string
    {
        $stage = ExpertAiRun::query()->where('run_id', $runId)->value('stage');

        return is_string($stage) ? $stage : null;
    }

    public function mark(string $runId, string $status): void
    {
        $run = $this->find($runId);
        if ($run === null) {
            return;
        }
        $run['status'] = $status;
        Cache::put($this->runKey($runId), $run, now()->addSeconds($this->runTtlSeconds()));
    }

    /**
     * Persist a bounded lifecycle update without putting request or material
     * content into the run record.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function stage(string $runId, string $stage, array $attributes = []): void
    {
        $record = ExpertAiRun::query()->where('run_id', $runId)->first();
        if ($record === null) {
            return;
        }

        $updates = ['stage' => $this->safeCode($stage, 32) ?? 'unknown'];
        foreach ([
            'user_message_id', 'assistant_message_id', 'selected_material_count', 'persisted_material_count',
            'resolved_material_count', 'active_material_count', 'resolved_mode', 'provider', 'model',
            'upstream_provider', 'upstream_model', 'first_token_at', 'last_activity_code',
        ] as $key) {
            if (array_key_exists($key, $attributes)) {
                $updates[$key] = $this->safeAttribute($key, $attributes[$key]);
            }
        }

        $metadata = $this->safeMetadata(is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : []);
        if ($metadata !== []) {
            $updates['metadata'] = [...($record->metadata ?? []), ...$metadata];
        }

        $record->forceFill($updates)->save();

        $cached = $this->find($runId);
        if ($cached !== null) {
            $cached['stage'] = $updates['stage'];
            Cache::put($this->runKey($runId), $cached, now()->addSeconds($this->runTtlSeconds()));
        }
    }

    public function recordActivity(string $runId, string $activityCode): void
    {
        $this->stage($runId, (string) (ExpertAiRun::query()->where('run_id', $runId)->value('stage') ?? 'unknown'), [
            'last_activity_code' => $activityCode,
        ]);
    }

    public function setAssistantMessagePublicId(string $runId, string $assistantMessagePublicId): void
    {
        $run = $this->find($runId);
        if ($run === null) {
            return;
        }

        $run['assistant_message_public_id'] = $assistantMessagePublicId;
        Cache::put($this->runKey($runId), $run, now()->addSeconds($this->runTtlSeconds()));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function finish(
        string $runId,
        string $status,
        string $finishReason,
        ?string $errorCode = null,
        bool $retryable = false,
        ?string $lastActivityCode = null,
        ?string $exceptionClass = null,
        ?float $startedAt = null,
        array $attributes = [],
    ): void {
        $terminalStatus = in_array($status, ['completed', 'cancelled', 'interrupted', 'failed'], true)
            ? $status
            : 'failed';
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        if ($exceptionClass !== null) {
            $metadata['exception_class'] = $exceptionClass;
        }

        DB::transaction(function () use ($runId, $terminalStatus, $finishReason, $errorCode, $retryable, $lastActivityCode, $exceptionClass, $startedAt, $attributes, $metadata): void {
            $record = ExpertAiRun::query()->where('run_id', $runId)->lockForUpdate()->first();
            if ($record === null) {
                return;
            }

            $eventStage = is_string($attributes['event_stage'] ?? null)
                ? $attributes['event_stage']
                : $record->stage;
            $this->stage($runId, $eventStage, [
                ...$attributes,
                'metadata' => $metadata,
                'last_activity_code' => $lastActivityCode,
            ]);
            $record->refresh();
            $durationMs = $startedAt === null
                ? max(0, (int) $record->started_at->diffInMilliseconds(now()))
                : max(0, (int) round((microtime(true) - $startedAt) * 1000));
            $record->forceFill([
                'status' => $terminalStatus,
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'finish_reason' => $this->safeCode($finishReason, 64),
                'error_code' => $errorCode === null ? null : $this->safeCode($errorCode, 100),
                'retryable' => $retryable,
                'last_activity_code' => $lastActivityCode === null ? $record->last_activity_code : $this->safeCode($lastActivityCode, 100),
            ])->save();

            $eventCode = match ($terminalStatus) {
                'completed' => 'run.completed',
                'cancelled' => 'run.cancelled',
                default => 'run.failed',
            };
            $eventStatus = in_array($terminalStatus, ['failed', 'interrupted'], true) ? 'failed' : $terminalStatus;
            $this->eventRecorder->record(
                $runId,
                'lifecycle',
                in_array($terminalStatus, ['failed', 'interrupted'], true) ? 'error' : ($terminalStatus === 'cancelled' ? 'warning' : 'info'),
                $eventCode,
                $eventStatus,
                [
                    'duration_ms' => $durationMs,
                    'error_code' => $errorCode,
                    'retryable' => $retryable,
                    'exception_class' => $exceptionClass,
                    'last_activity_code' => $lastActivityCode,
                ],
            );
        });

        $this->mark($runId, $terminalStatus);
    }

    private function runTtlSeconds(): int
    {
        return max((int) config('expert.streaming.run_ttl_seconds', 1800), (int) config('expert.streaming.absolute_timeout_seconds', 600) + 60);
    }

    private function runKey(string $runId): string
    {
        return 'expert:chat:run:'.$runId;
    }

    private function conversationLockKey(ExpertConversation $conversation): string
    {
        return 'expert:chat:conversation:'.$conversation->id.':user:'.$conversation->project->user_id;
    }

    private function safeAttribute(string $key, mixed $value): mixed
    {
        if (str_ends_with($key, '_count')) {
            return max(0, (int) $value);
        }
        if (in_array($key, ['user_message_id', 'assistant_message_id'], true)) {
            return $value === null ? null : max(0, (int) $value);
        }
        if ($key === 'first_token_at') {
            return $value ?? now();
        }
        if ($key === 'resolved_mode') {
            return is_string($value) ? ExpertModeResolution::normalise($value) : null;
        }
        if ($key === 'last_activity_code') {
            return $value === null ? null : $this->safeCode((string) $value, 100);
        }
        if (in_array($key, ['provider', 'upstream_provider'], true)) {
            return $this->safeIdentifier($value, '/^[a-z0-9_-]{1,64}$/i');
        }
        if (in_array($key, ['model', 'upstream_model'], true)) {
            return $this->safeIdentifier($value, '/^[a-z0-9._\/-]{1,255}$/i');
        }

        return $value;
    }

    private function safeIdentifier(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private function safeCode(string $value, int $maxLength): ?string
    {
        return preg_match('/^[a-z0-9_.-]{1,'.$maxLength.'}$/i', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function safeMetadata(array $metadata): array
    {
        $allowed = [
            'task_profile', 'execution_strategy', 'scope', 'coverage_mode', 'material_scope',
            'cross_document', 'fallback_used', 'fallback_reason', 'required_capabilities',
            'material_count', 'pdf_count', 'image_count', 'source_bytes', 'page_count',
            'estimated_text_chars', 'prepared_payload_bytes', 'estimated_context_tokens',
            'coverage_requested', 'coverage_processed', 'coverage_failed', 'coverage_skipped',
            'coverage_complete', 'intent_confidence', 'exception_class', 'http_status_class',
        ];
        $safe = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }
            $value = $metadata[$key];
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value) && $key !== 'exception_class' && strlen($value) <= 100) {
                $safe[$key] = $value;
            } elseif ($key === 'exception_class' && is_string($value) && preg_match('/^[A-Za-z0-9_\\\\]{1,255}$/', $value) === 1) {
                $safe[$key] = $value;
            } elseif ($key === 'required_capabilities' && is_array($value)) {
                $safe[$key] = array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && preg_match('/^[a-z0-9_.-]{1,64}$/i', $item) === 1));
            }
        }

        return $safe;
    }
}
