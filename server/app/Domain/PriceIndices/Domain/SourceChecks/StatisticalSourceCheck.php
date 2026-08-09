<?php

namespace App\Domain\PriceIndices\Domain\SourceChecks;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\SourceCheckStatus;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use Database\Factories\StatisticalSourceCheckFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalSourceCheck extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'source_id',
        'started_at',
        'finished_at',
        'status',
        'candidate_url',
        'http_status',
        'content_type',
        'content_length',
        'etag',
        'last_modified',
        'downloaded_file_id',
        'error_code',
        'error_message',
        'details_json',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => SourceCheckStatus::class,
            'http_status' => 'integer',
            'content_length' => 'integer',
            'details_json' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(StatisticalSource::class, 'source_id');
    }

    public function downloadedFile(): BelongsTo
    {
        return $this->belongsTo(StatisticalSourceFile::class, 'downloaded_file_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalSourceCheckFactory::new();
    }
}
