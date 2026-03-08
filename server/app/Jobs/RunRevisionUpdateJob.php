<?php

namespace App\Jobs;

use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunRevisionUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $runId,
        public bool $retryOnly = false
    ) {}

    public function handle(): void
    {
        $run = RevisionRun::find($this->runId);
        if (!$run) {
            return;
        }

        $run->update([
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ]);

        $query = RevisionRunItem::where('revision_run_id', $run->id);
        if ($this->retryOnly) {
            $query->where('status', '!=', RevisionRunItem::STATUS_OK);
        }
        $items = $query->get();

        // Reset items to PENDING so the UI shows correct in-progress counts
        foreach ($items as $item) {
            $item->update(['status' => RevisionRunItem::STATUS_PENDING, 'message' => null]);
        }

        foreach ($items as $item) {
            UpdateMaterialObservationForRevisionItem::dispatchSync($item->id);
        }

        $total = $run->items()->count();
        $ok = $run->items()->where('status', RevisionRunItem::STATUS_OK)->count();
        $failed = $total - $ok;

        $run->update([
            'status' => $failed === 0 ? RevisionRun::STATUS_READY : RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => $total,
            'ok_items' => $ok,
            'failed_items' => $failed,
            'finished_at' => now(),
        ]);
    }
}
