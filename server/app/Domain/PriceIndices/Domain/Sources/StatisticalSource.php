<?php

namespace App\Domain\PriceIndices\Domain\Sources;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\SourceChecks\StatisticalSourceCheck;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Database\Factories\StatisticalSourceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalSource extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'code',
        'name',
        'source_page_url',
        'download_url_template',
        'filename_template',
        'http_method',
        'is_enabled',
        'automatic_check_enabled',
        'last_checked_at',
        'last_success_at',
        'next_check_at',
        'consecutive_failures',
        'last_http_status',
        'last_error_code',
        'last_error_message',
        'settings_json',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'automatic_check_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_check_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_http_status' => 'integer',
            'settings_json' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function sourceFiles(): HasMany
    {
        return $this->hasMany(StatisticalSourceFile::class, 'source_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(StatisticalSourceCheck::class, 'source_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalSourceFactory::new();
    }
}
