<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductPriceEvidenceAsset extends Model
{
    use \App\Models\Concerns\AccountsStorageReferences;

    protected $hidden = ['file_path', 'storage_disk'];
    use HasFactory;

    public const TYPE_SCREENSHOT = 'screenshot';
    public const TYPE_FILE = 'file';
    public const TYPE_IMAGE = 'image';
    public const TYPE_LINK = 'link';

    protected $fillable = [
        'finished_product_price_source_id',
        'asset_type',
        'file_path',
        'storage_disk',
        'original_name',
        'mime_type',
        'file_size',
        'source_url',
        'content_hash',
        'captured_at',
        'metadata',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(FinishedProductPriceSource::class, 'finished_product_price_source_id');
    }
}
