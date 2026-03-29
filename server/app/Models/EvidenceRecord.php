<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvidenceRecord extends Model
{
    protected $table = 'evidence_records';

    protected $fillable = [
        'uuid',
        'cost_component',
        'source_type',
        'capture_method',
        'verification_status',
        'source_url',
        'source_domain',
        'observed_price',
        'currency',
        'observed_at',
        'extracted_name',
        'extracted_article',
        'metadata_json',
        'confidence_score',
        'trust_score',
        'created_by',
    ];

    protected $casts = [
        'observed_price' => 'decimal:2',
        'observed_at' => 'datetime',
        'metadata_json' => 'array',
        'confidence_score' => 'integer',
        'trust_score' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(GenericEvidenceAsset::class, 'evidence_record_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(EvidenceLink::class, 'evidence_record_id');
    }

    public function materialPriceHistories(): HasMany
    {
        return $this->hasMany(MaterialPriceHistory::class, 'evidence_record_id');
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EstimateEvidenceItem::class, 'evidence_record_id');
    }
}
