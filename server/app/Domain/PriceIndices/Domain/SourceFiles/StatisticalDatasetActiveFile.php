<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalDatasetActiveFile extends Model
{
    use HasPublicId;

    protected $fillable = [
        'dataset_id',
        'reporting_year',
        'reporting_month',
        'source_file_id',
        'activated_by_user_id',
        'activated_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $pointer): void {
            $sourceFile = StatisticalSourceFile::query()->find($pointer->source_file_id);

            if ($sourceFile === null
                || $sourceFile->dataset_id !== (int) $pointer->dataset_id
                || $sourceFile->reporting_year !== (int) $pointer->reporting_year
                || $sourceFile->reporting_month !== (int) $pointer->reporting_month
                || ! in_array($sourceFile->status, [SourceFileStatus::Approved, SourceFileStatus::Active], true)
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Active pointer must match an approved or active source file dataset and period.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reporting_year' => 'integer',
            'reporting_month' => 'integer',
            'activated_at' => 'datetime',
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

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }
}
