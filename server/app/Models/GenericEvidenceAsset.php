<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenericEvidenceAsset extends Model
{
    protected $table = 'generic_evidence_assets';

    protected $fillable = [
        'uuid',
        'evidence_record_id',
        'asset_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'sha256',
        'metadata_json',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'metadata_json' => 'array',
        'uploaded_by' => 'integer',
    ];

    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class, 'evidence_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
