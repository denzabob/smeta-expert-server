<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateEvidenceRun extends Model
{
    protected $table = 'estimate_evidence_runs';

    protected $fillable = [
        'uuid',
        'project_id',
        'initiated_by',
        'status',
        'total_items',
        'completed_items',
        'failed_items',
        'metadata_json',
        'snapshot_json',
        'started_at',
        'finalized_at',
    ];

    protected $casts = [
        'total_items' => 'integer',
        'completed_items' => 'integer',
        'failed_items' => 'integer',
        'metadata_json' => 'array',
        'snapshot_json' => 'array',
        'started_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateEvidenceItem::class, 'evidence_run_id');
    }
}
