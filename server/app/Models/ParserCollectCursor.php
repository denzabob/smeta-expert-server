<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ParserCollectCursor - Resume point for URL collection phase.
 * 
 * Allows collect phase to be interrupted and resumed without
 * losing progress or re-collecting already sent URLs.
 */
class ParserCollectCursor extends Model
{
    protected $table = 'parser_collect_cursors';

    protected $fillable = [
        'session_id',
        'supplier_name',
        'current_category',
        'current_page',
        'visited_pages',
        'urls_found_total',
        'urls_unique_total',
        'urls_sent_total',
        'duplicates_dropped',
        'elapsed_seconds',
        'last_chunk_sent_at',
        'stop_reason',
        'is_complete',
    ];

    protected $casts = [
        'current_page' => 'integer',
        'visited_pages' => 'integer',
        'urls_found_total' => 'integer',
        'urls_unique_total' => 'integer',
        'urls_sent_total' => 'integer',
        'duplicates_dropped' => 'integer',
        'elapsed_seconds' => 'float',
        'last_chunk_sent_at' => 'datetime',
        'is_complete' => 'boolean',
    ];

    /**
     * Get the parsing session.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ParsingSession::class, 'session_id');
    }

    /**
     * Create or get cursor for session.
     */
    public static function getOrCreate(int $sessionId, string $supplier): self
    {
        return static::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'supplier_name' => $supplier,
                'current_page' => 0,
                'visited_pages' => 0,
                'urls_found_total' => 0,
                'urls_unique_total' => 0,
                'urls_sent_total' => 0,
                'duplicates_dropped' => 0,
                'elapsed_seconds' => 0,
                'is_complete' => false,
            ]
        );
    }

    /**
     * Update cursor after chunk sent.
     */
    public function afterChunkSent(
        int $urlsSent,
        int $duplicatesInChunk,
        string $currentCategory = null,
        int $currentPage = null,
        float $elapsedSeconds = null
    ): self {
        $this->update([
            'urls_sent_total' => $this->urls_sent_total + $urlsSent,
            'duplicates_dropped' => $this->duplicates_dropped + $duplicatesInChunk,
            'current_category' => $currentCategory ?? $this->current_category,
            'current_page' => $currentPage ?? $this->current_page,
            'elapsed_seconds' => $elapsedSeconds ?? $this->elapsed_seconds,
            'last_chunk_sent_at' => now(),
        ]);
        
        return $this;
    }

    /**
     * Mark collection as complete.
     */
    public function markComplete(string $stopReason = null): self
    {
        $this->update([
            'is_complete' => true,
            'stop_reason' => $stopReason,
        ]);
        
        return $this;
    }

    /**
     * Get resume data for Python.
     */
    public function getResumeData(): array
    {
        return [
            'current_category' => $this->current_category,
            'current_page' => $this->current_page,
            'visited_pages' => $this->visited_pages,
            'urls_sent_total' => $this->urls_sent_total,
            'elapsed_seconds' => $this->elapsed_seconds,
            'is_complete' => $this->is_complete,
        ];
    }

    /**
     * Get stats summary.
     */
    public function getStats(): array
    {
        return [
            'urls_found_total' => $this->urls_found_total,
            'urls_unique_total' => $this->urls_unique_total,
            'urls_sent_total' => $this->urls_sent_total,
            'duplicates_dropped' => $this->duplicates_dropped,
            'elapsed_seconds' => $this->elapsed_seconds,
            'stop_reason' => $this->stop_reason,
            'is_complete' => $this->is_complete,
        ];
    }
}
