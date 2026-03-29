<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceAsset extends Model
{
    protected $fillable = [
        'uuid',
        'evidence_artifact_id',
        'asset_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'sha256',
        'metadata_json',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata_json' => 'array',
    ];

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(EvidenceArtifact::class);
    }
}
