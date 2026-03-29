<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EstimateEvidenceItem extends Model
{
    protected $table = 'estimate_evidence_items';

    protected $fillable = [
        'uuid',
        'evidence_run_id',
        'cost_component',
        'label',
        'status',
        'resolution_type',
        'subject_type',
        'subject_id',
        'evidence_record_id',
        'source_url',
        'effective_value',
        'currency',
        'diagnostics_json',
    ];

    protected $casts = [
        'diagnostics_json' => 'array',
        'effective_value' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EstimateEvidenceRun::class, 'evidence_run_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class, 'evidence_record_id');
    }
}
