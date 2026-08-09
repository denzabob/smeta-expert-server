<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Models\User;
use Database\Factories\StatisticalSourceFileFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StatisticalSourceFile extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'source_id',
        'acquisition_method',
        'reporting_year',
        'reporting_month',
        'source_url',
        'original_filename',
        'stored_path',
        'storage_disk',
        'mime_type',
        'file_size',
        'sha256',
        'http_etag',
        'http_last_modified',
        'downloaded_at',
        'uploaded_by_user_id',
        'detected_at',
        'status',
        'validation_status',
        'validation_summary_json',
        'rejection_reason',
        'reviewed_by_user_id',
        'reviewed_at',
        'activated_by_user_id',
        'activated_at',
        'supersedes_file_id',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $file): void {
            if ($file->source_id === null) {
                return;
            }

            $sourceDatasetId = StatisticalSource::query()
                ->whereKey($file->source_id)
                ->value('dataset_id');

            if ($sourceDatasetId === null || (int) $sourceDatasetId !== (int) $file->dataset_id) {
                throw new PriceIndicesInvariantViolation(
                    'Source file and source must belong to the same dataset.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'acquisition_method' => AcquisitionMethod::class,
            'reporting_year' => 'integer',
            'reporting_month' => 'integer',
            'file_size' => 'integer',
            'downloaded_at' => 'datetime',
            'detected_at' => 'datetime',
            'status' => SourceFileStatus::class,
            'validation_status' => ValidationStatus::class,
            'validation_summary_json' => 'array',
            'reviewed_at' => 'datetime',
            'activated_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(StatisticalSource::class, 'source_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_file_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_file_id');
    }

    public function activePointer(): HasOne
    {
        return $this->hasOne(StatisticalDatasetActiveFile::class, 'source_file_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalSourceFileFactory::new();
    }
}
