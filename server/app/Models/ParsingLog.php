<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParsingLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'parsing_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'session_id',
        'url',
        'level',
        'message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Disable updated_at timestamp.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the parsing session that owns this log.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ParsingSession::class, 'session_id');
    }

    /**
     * Scope to get error logs.
     */
    public function scopeErrors($query)
    {
        return $query->where('level', 'error');
    }

    /**
     * Scope to get warning logs.
     */
    public function scopeWarnings($query)
    {
        return $query->where('level', 'warning');
    }

    /**
     * Scope to get info logs.
     */
    public function scopeInfo($query)
    {
        return $query->where('level', 'info');
    }

    /**
     * Check if this is an error log.
     */
    public function isError(): bool
    {
        return $this->level === 'error' || $this->level === 'critical';
    }

    /**
     * Check if this is a warning log.
     */
    public function isWarning(): bool
    {
        return $this->level === 'warning';
    }

    /**
     * Format log for display.
     */
    public function formatForDisplay(): string
    {
        $emoji = match($this->level) {
            'debug' => '🔍',
            'info' => 'ℹ️',
            'warning' => '⚠️',
            'error' => '❌',
            'critical' => '🚨',
            default => '•'
        };

        return "{$emoji} [{$this->level}] {$this->message}";
    }
}
