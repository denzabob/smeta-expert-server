<?php

namespace App\Domain\PriceIndices\Domain\Datasets;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalDatasetActiveFile;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use Database\Factories\StatisticalDatasetFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalDataset extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'code',
        'name',
        'description',
        'provider_code',
        'provider_name',
        'data_kind',
        'frequency',
        'classifier_code',
        'territory_scope',
        'is_enabled',
        'automatic_check_enabled',
        'check_schedule',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'automatic_check_enabled' => 'boolean',
            'metadata_json' => 'array',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(StatisticalSource::class, 'dataset_id');
    }

    public function sourceFiles(): HasMany
    {
        return $this->hasMany(StatisticalSourceFile::class, 'dataset_id');
    }

    public function activeFiles(): HasMany
    {
        return $this->hasMany(StatisticalDatasetActiveFile::class, 'dataset_id');
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(StatisticalIndicator::class, 'dataset_id');
    }

    public function classifierItems(): HasMany
    {
        return $this->hasMany(StatisticalClassifierItem::class, 'dataset_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(StatisticalSeries::class, 'dataset_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(StatisticalImport::class, 'dataset_id');
    }

    public function activeImport(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StatisticalDatasetActiveImport::class, 'dataset_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalDatasetFactory::new();
    }
}
