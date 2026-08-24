<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use Database\Factories\StatisticalClassifierImportFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StatisticalClassifierImport extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'classifier_id',
        'source_file_id',
        'attempt',
        'status',
        'parser_code',
        'parser_version',
        'started_at',
        'finished_at',
        'nodes_parsed',
        'sections_count',
        'validation_errors_count',
        'validation_warnings_count',
        'validation_summary_json',
        'error_json',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => ClassifierImportStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'nodes_parsed' => 'integer',
            'sections_count' => 'integer',
            'validation_errors_count' => 'integer',
            'validation_warnings_count' => 'integer',
            'validation_summary_json' => 'array',
            'error_json' => 'array',
        ];
    }

    public function classifier(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifier::class, 'classifier_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierSourceFile::class, 'source_file_id');
    }

    public function version(): HasOne
    {
        return $this->hasOne(StatisticalClassifierVersion::class, 'classifier_import_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalClassifierImportFactory::new();
    }
}
