<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $backoff = 30;
    public $deleteWhenMissingModels = true;

    public function __construct(
        public Notification $notification
    ) {}

    public function handle(): void
    {
        $notification = $this->notification;

        // Check if cancelled before processing
        if ($notification->status === 'cancelled') {
            Log::info("Notification #{$notification->id} was cancelled, skipping dispatch.");
            return;
        }

        // Mark as sending
        $notification->update(['status' => 'sending']);

        try {
            $userIds = $notification->resolveAudienceUserIds();

            if ($userIds->isEmpty()) {
                Log::warning("Notification #{$notification->id} has no target users.");
                $notification->update(['status' => 'sent']);
                return;
            }

            $now = now();

            // Batch insert user_notifications (chunk by 500)
            $userIds->chunk(500)->each(function ($chunk) use ($notification, $now) {
                // Re-check cancellation between chunks
                $notification->refresh();
                if ($notification->status === 'cancelled') {
                    return false; // stop chunking
                }

                $rows = $chunk->map(fn ($userId) => [
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'delivered_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->toArray();

                // Upsert: skip duplicates
                UserNotification::upsert(
                    $rows,
                    ['notification_id', 'user_id'],
                    ['delivered_at', 'updated_at']
                );
            });

            // Mark as sent
            $notification->refresh();
            if ($notification->status !== 'cancelled') {
                $notification->update(['status' => 'sent']);
            }

            Log::info("Notification #{$notification->id} dispatched to {$userIds->count()} users.");
        } catch (\Throwable $e) {
            Log::error("Notification #{$notification->id} dispatch failed: {$e->getMessage()}");
            throw $e; // Let the queue retry
        }
    }
}
