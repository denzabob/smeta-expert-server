<?php

namespace App\Domain\PriceIndices\Domain\Observations;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Database\Factories\StatisticalObservationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatisticalObservation extends Model
{
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'import_id',
        'series_id',
        'period_start',
        'value',
        'missing_reason',
        'source_file_id',
        'sheet_name',
        'source_row',
        'source_column',
        'source_cell_address',
        'source_value_raw',
        'footnote_marker',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $observation): void {
            $import = StatisticalImport::query()->find($observation->import_id);
            $series = StatisticalSeries::query()->find($observation->series_id);
            $sourceFile = StatisticalSourceFile::query()->find($observation->source_file_id);

            if ($import === null
                || $series === null
                || $sourceFile === null
                || $series->dataset_id !== $import->dataset_id
                || $sourceFile->id !== $import->source_file_id
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Observation provenance and series must match its import dataset and source file.'
                );
            }
        });

        static::updating(function (): never {
            throw new PriceIndicesInvariantViolation('Statistical observations are immutable.');
        });

        static::deleting(function (self $observation): void {
            $status = $observation->import()->firstOrFail()->status;

            if (in_array($status, [
                StatisticalImportStatus::ReadyForPublish,
                StatisticalImportStatus::Published,
                StatisticalImportStatus::Superseded,
            ], true)) {
                throw new PriceIndicesInvariantViolation(
                    'Observations from successful imports cannot be deleted.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'value' => 'decimal:10',
            'missing_reason' => StatisticalObservationMissingReason::class,
            'source_row' => 'integer',
            'metadata_json' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(StatisticalImport::class, 'import_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(StatisticalSeries::class, 'series_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StatisticalSourceFile::class, 'source_file_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalObservationFactory::new();
    }
}
