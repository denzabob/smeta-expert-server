<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\SupplierUrl;

class ParsingSessionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $queueProcessed = null;
        if ($this->supplier_name) {
            $queueCounts = SupplierUrl::query()
                ->selectRaw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_count")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
                ->selectRaw("SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_count")
                ->where('supplier_name', $this->supplier_name)
                ->first();

            if ($queueCounts) {
                $queueProcessed = (int) $queueCounts->done_count
                    + (int) $queueCounts->failed_count
                    + (int) $queueCounts->blocked_count;
            }
        }

        $pagesProcessed = (int) ($this->pages_processed ?? 0);
        if ($queueProcessed !== null && $queueProcessed > $pagesProcessed) {
            $pagesProcessed = $queueProcessed;
        }

        return [
            'id' => $this->id,
            'supplier' => $this->supplier_name,
            'status' => $this->status,
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'pid' => $this->pid,
            'last_heartbeat_at' => $this->last_heartbeat?->toISOString(),
            
            // Map database fields to frontend expected fields
            'total_urls' => $this->total_urls ?? 0,
            'processed_count' => $pagesProcessed,
            'success_count' => $this->items_updated ?? 0,
            'error_count' => $this->errors_count ?? 0,
            'screenshots_taken' => 0, // TODO: Add this field to database
            
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
