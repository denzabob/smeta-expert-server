<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalSourceFile> */
class StatisticalSourceFileFactory extends Factory
{
    protected $model = StatisticalSourceFile::class;

    public function definition(): array
    {
        $filename = 'indices_'.Str::lower(Str::random(12)).'.xlsx';

        return [
            'dataset_id' => StatisticalDataset::factory(),
            'source_id' => null,
            'acquisition_method' => AcquisitionMethod::ManualUpload,
            'reporting_year' => 2026,
            'reporting_month' => 1,
            'source_url' => null,
            'original_filename' => $filename,
            'stored_path' => 'price-indices/test/'.$filename,
            'storage_disk' => 'local',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => 1024,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'downloaded_at' => null,
            'uploaded_by_user_id' => null,
            'detected_at' => now(),
            'status' => SourceFileStatus::PendingReview,
            'validation_status' => ValidationStatus::Passed,
            'validation_summary_json' => ['valid' => true],
            'metadata_json' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => SourceFileStatus::Approved,
        ]);
    }
}
