<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use Database\Factories\StatisticalClassifierSourceFileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalClassifierSourceFile extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'classifier_id',
        'trust_tier',
        'source_page_url',
        'download_url',
        'resolved_url',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'size_bytes',
        'sha256',
        'etag',
        'last_modified_at',
        'downloaded_at',
        'declared_version_label',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'trust_tier' => ClassifierSourceTrustTier::class,
            'size_bytes' => 'integer',
            'last_modified_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function classifier(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifier::class, 'classifier_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(StatisticalClassifierImport::class, 'source_file_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierSourceFileFactory::new();
    }
}
