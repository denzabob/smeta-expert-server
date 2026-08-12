<?php

namespace App\Domain\PriceIndices\Domain\PublicPages;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalPublicSeriesPage extends Model
{
    use HasPublicId;

    protected $fillable = [
        'dataset_id',
        'import_id',
        'series_id',
        'classifier_item_id',
        'source_file_id',
        'slug',
        'is_indexable',
        'indexability_status',
        'period_from',
        'period_to',
        'observations_count',
        'factors_count',
        'coefficient_raw',
        'coefficient',
        'change_percent_raw',
        'change_percent',
        'min_index_value',
        'min_index_period',
        'max_index_value',
        'max_index_period',
        'generated_at',
        'source_published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_indexable' => 'boolean',
            'indexability_status' => PublicSeriesIndexabilityStatus::class,
            'period_from' => 'date',
            'period_to' => 'date',
            'observations_count' => 'integer',
            'factors_count' => 'integer',
            'coefficient_raw' => 'decimal:20',
            'coefficient' => 'decimal:12',
            'change_percent_raw' => 'decimal:20',
            'change_percent' => 'decimal:2',
            'min_index_value' => 'decimal:10',
            'min_index_period' => 'date',
            'max_index_value' => 'decimal:10',
            'max_index_period' => 'date',
            'generated_at' => 'datetime',
            'source_published_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StatisticalImport::class, 'import_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(StatisticalSeries::class, 'series_id');
    }

    public function classifierItem(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierItem::class, 'classifier_item_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StatisticalSourceFile::class, 'source_file_id');
    }
}
