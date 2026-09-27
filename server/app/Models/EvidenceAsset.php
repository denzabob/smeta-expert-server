<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceAsset extends Model
{
    protected $hidden = ['file_path', 'storage_disk'];
    protected $fillable = [
        'uuid',
        'evidence_artifact_id',
        'asset_type',
        'file_path',
        'storage_disk',
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
