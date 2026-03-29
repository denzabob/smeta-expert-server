<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EvidenceLink extends Model
{
    protected $table = 'evidence_links';

    protected $fillable = [
        'evidence_record_id',
        'linkable_type',
        'linkable_id',
        'relation_type',
    ];

    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class, 'evidence_record_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
