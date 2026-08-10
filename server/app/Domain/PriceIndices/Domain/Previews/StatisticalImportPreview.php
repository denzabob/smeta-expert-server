<?php

namespace App\Domain\PriceIndices\Domain\Previews;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Database\Factories\StatisticalImportPreviewFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StatisticalImportPreview extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'source_file_id',
        'importer_code',
        'importer_version',
        'status',
        'cache_key',
        'requested_by_user_id',
        'started_at',
        'finished_at',
        'failed_at',
        'expires_at',
        'sheets_total',
        'supported_sheets',
        'ignored_sheets',
        'commodity_occurrences',
        'unique_classifier_items',
        'observation_candidates',
        'numeric_count',
        'missing_count',
        'footnoted_count',
        'warnings_count',
        'fatal_errors_count',
        'result_json',
        'failure_code',
        'failure_message',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $preview): void {
            $sourceDatasetId = StatisticalSourceFile::query()
                ->whereKey($preview->source_file_id)
                ->value('dataset_id');
            if ($sourceDatasetId === null || (int) $sourceDatasetId !== (int) $preview->dataset_id) {
                throw new PriceIndicesInvariantViolation(
                    'Statistical preview and source file must belong to the same dataset.'
                );
            }

            if ($preview->exists
                && $preview->getRawOriginal('status') === StatisticalImportPreviewStatus::Ready->value
                && $preview->isDirty('result_json')
            ) {
                throw new PriceIndicesInvariantViolation('A ready statistical preview result is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => StatisticalImportPreviewStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
            'sheets_total' => 'integer',
            'supported_sheets' => 'integer',
            'ignored_sheets' => 'integer',
            'commodity_occurrences' => 'integer',
            'unique_classifier_items' => 'integer',
            'observation_candidates' => 'integer',
            'numeric_count' => 'integer',
            'missing_count' => 'integer',
            'footnoted_count' => 'integer',
            'warnings_count' => 'integer',
            'fatal_errors_count' => 'integer',
            'result_json' => 'array',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalImportPreviewFactory::new();
    }
}
