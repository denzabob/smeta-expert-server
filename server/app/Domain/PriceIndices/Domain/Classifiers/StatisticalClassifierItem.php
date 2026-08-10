<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Database\Factories\StatisticalClassifierItemFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalClassifierItem extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'classifier_code',
        'item_code',
        'name',
        'normalized_name',
        'parent_item_id',
        'valid_from',
        'valid_to',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->parent_item_id === null) {
                return;
            }

            $parentDatasetId = self::query()->whereKey($item->parent_item_id)->value('dataset_id');

            if ($parentDatasetId === null || (int) $parentDatasetId !== (int) $item->dataset_id) {
                throw new PriceIndicesInvariantViolation(
                    'Classifier item and parent must belong to the same dataset.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'metadata_json' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(StatisticalSeries::class, 'classifier_item_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierItemFactory::new();
    }
}
