<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevisionRun extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_NEEDS_MANUAL = 'NEEDS_MANUAL';
    public const STATUS_READY = 'READY';
    public const STATUS_FINALIZED = 'FINALIZED';
    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'project_id',
        'initiator_user_id',
        'status',
        'total_items',
        'ok_items',
        'failed_items',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'ok_items' => 'integer',
        'failed_items' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RevisionRunItem::class);
    }

    public function evidenceArtifacts(): HasMany
    {
        return $this->hasMany(EvidenceArtifact::class);
    }
}
