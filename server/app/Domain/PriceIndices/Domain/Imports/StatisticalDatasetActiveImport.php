<?php

namespace App\Domain\PriceIndices\Domain\Imports;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalDatasetActiveImport extends Model
{
    use HasPublicId;

    protected $fillable = [
        'dataset_id',
        'import_id',
        'published_by_user_id',
        'published_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $pointer): void {
            $import = StatisticalImport::query()->find($pointer->import_id);

            if ($import === null
                || $import->dataset_id !== (int) $pointer->dataset_id
                || $import->status !== StatisticalImportStatus::Published
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Active import pointer must reference a published import from the same dataset.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StatisticalImport::class, 'import_id');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
