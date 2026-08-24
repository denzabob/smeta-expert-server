<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalClassifierSourceFile> */
class StatisticalClassifierSourceFileFactory extends Factory
{
    protected $model = StatisticalClassifierSourceFile::class;

    public function definition(): array
    {
        $artifactId = fake()->uuid();

        return [
            'classifier_id' => StatisticalClassifier::factory(),
            'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
            'source_page_url' => 'https://official.example.test/classification',
            'download_url' => 'https://official.example.test/classifier.zip',
            'resolved_url' => 'https://official.example.test/classifier.zip',
            'original_filename' => 'classifier.zip',
            'storage_disk' => 'local',
            'storage_path' => "price-indices/classifier-fixtures/{$artifactId}.zip",
            'mime_type' => 'application/zip',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', $artifactId),
            'etag' => null,
            'last_modified_at' => null,
            'downloaded_at' => null,
            'declared_version_label' => null,
            'metadata_json' => null,
        ];
    }

    public function referenceFixture(): static
    {
        return $this->state(fn (): array => [
            'trust_tier' => ClassifierSourceTrustTier::ReferenceFixture,
        ]);
    }
}
