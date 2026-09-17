<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertConversation;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ExpertChatRunRegistry
{
    public function acquireConversation(ExpertConversation $conversation): Lock
    {
        $lock = Cache::lock($this->conversationLockKey($conversation), (int) config('expert.streaming.lock_seconds', 900));

        if (! $lock->get()) {
            throw new ExpertChatRunInProgressException;
        }

        return $lock;
    }

    /** @return array{run_id:string,conversation_public_id:string,project_id:int,user_id:int,status:string,cancel_requested:bool,created_at:string,assistant_message_public_id:?string} */
    public function create(ExpertConversation $conversation, ?string $assistantMessagePublicId = null): array
    {
        $run = [
            'run_id' => (string) Str::uuid(),
            'conversation_public_id' => $conversation->public_id,
            'project_id' => (int) $conversation->project_id,
            'user_id' => (int) $conversation->project->user_id,
            'status' => 'starting',
            'cancel_requested' => false,
            'created_at' => now()->toIso8601String(),
            'assistant_message_public_id' => $assistantMessagePublicId,
        ];

        Cache::put($this->runKey($run['run_id']), $run, now()->addSeconds((int) config('expert.streaming.run_ttl_seconds', 1800)));

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
            Cache::put($this->runKey($runId), $run, now()->addSeconds((int) config('expert.streaming.run_ttl_seconds', 1800)));

            return $run;
        } finally {
            $lock->release();
        }
    }

    public function isCancellationRequested(string $runId): bool
    {
        return (bool) ($this->find($runId)['cancel_requested'] ?? false);
    }

    public function mark(string $runId, string $status): void
    {
        $run = $this->find($runId);
        if ($run === null) {
            return;
        }
        $run['status'] = $status;
        Cache::put($this->runKey($runId), $run, now()->addSeconds((int) config('expert.streaming.run_ttl_seconds', 1800)));
    }

    private function runKey(string $runId): string
    {
        return 'expert:chat:run:'.$runId;
    }

    private function conversationLockKey(ExpertConversation $conversation): string
    {
        return 'expert:chat:conversation:'.$conversation->id.':user:'.$conversation->project->user_id;
    }
}
