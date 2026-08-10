<?php

namespace App\Domain\PriceIndices\Domain\Imports;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Database\Factories\StatisticalImportFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StatisticalImport extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'source_file_id',
        'importer_code',
        'importer_version',
        'attempt_no',
        'retry_of_import_id',
        'status',
        'successful_dedupe_key',
        'started_at',
        'finished_at',
        'ready_at',
        'published_at',
        'superseded_at',
        'failed_at',
        'rows_scanned',
        'observations_parsed',
        'observations_valid',
        'observations_rejected',
        'warnings_count',
        'errors_count',
        'initiated_by_user_id',
        'published_by_user_id',
        'supersedes_import_id',
        'failure_code',
        'failure_message',
        'validation_summary_json',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $import): void {
            $sourceDatasetId = StatisticalSourceFile::query()
                ->whereKey($import->source_file_id)
                ->value('dataset_id');

            if ($sourceDatasetId === null || (int) $sourceDatasetId !== (int) $import->dataset_id) {
                throw new PriceIndicesInvariantViolation(
                    'Statistical import and source file must belong to the same dataset.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'status' => StatisticalImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'ready_at' => 'datetime',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
            'failed_at' => 'datetime',
            'rows_scanned' => 'integer',
            'observations_parsed' => 'integer',
            'observations_valid' => 'integer',
            'observations_rejected' => 'integer',
            'warnings_count' => 'integer',
            'errors_count' => 'integer',
            'validation_summary_json' => 'array',
            'metadata_json' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StatisticalSourceFile::class, 'source_file_id');
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_import_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_import_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(StatisticalImportIssue::class, 'import_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(StatisticalObservation::class, 'import_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_import_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_import_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function activePointer(): HasOne
    {
        return $this->hasOne(StatisticalDatasetActiveImport::class, 'import_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalImportFactory::new();
    }
}
