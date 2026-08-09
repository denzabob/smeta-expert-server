<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\SourceChecks\StatisticalSourceCheck;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalDatasetActiveFile;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceIndicesSchemaAndModelsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_models_use_numeric_keys_and_uuid_public_route_keys(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $source = StatisticalSource::factory()->for($dataset, 'dataset')->create();
        $file = StatisticalSourceFile::factory()->for($dataset, 'dataset')->create();
        $check = StatisticalSourceCheck::factory()->for($source, 'source')->create();
        $pointer = StatisticalDatasetActiveFile::query()->create([
            'dataset_id' => $dataset->id,
            'reporting_year' => $file->reporting_year,
            'reporting_month' => $file->reporting_month,
            'source_file_id' => StatisticalSourceFile::factory()
                ->for($dataset, 'dataset')
                ->approved()
                ->create([
                    'reporting_year' => $file->reporting_year,
                    'reporting_month' => $file->reporting_month,
                ])->id,
            'activated_at' => now(),
        ]);

        foreach ([$dataset, $source, $file, $check, $pointer] as $model) {
            $this->assertIsInt($model->id);
            $this->assertTrue(Str::isUuid($model->public_id));
            $this->assertSame('public_id', $model->getRouteKeyName());
        }
    }

    public function test_dataset_code_is_unique(): void
    {
        $dataset = StatisticalDataset::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalDataset::factory()->create([
            'code' => $dataset->code,
        ]);
    }

    public function test_dataset_public_id_is_unique(): void
    {
        $dataset = StatisticalDataset::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalDataset::factory()->create([
            'public_id' => $dataset->public_id,
        ]);
    }

    public function test_source_code_is_unique_within_dataset(): void
    {
        $source = StatisticalSource::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalSource::factory()->create([
            'dataset_id' => $source->dataset_id,
            'code' => $source->code,
        ]);
    }

    public function test_same_source_code_is_allowed_for_different_datasets(): void
    {
        $code = 'monthly_source';
        $first = StatisticalSource::factory()->create(['code' => $code]);
        $second = StatisticalSource::factory()->create(['code' => $code]);

        $this->assertNotSame($first->dataset_id, $second->dataset_id);
    }

    public function test_source_file_hash_is_unique_within_dataset(): void
    {
        $file = StatisticalSourceFile::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create([
            'dataset_id' => $file->dataset_id,
            'sha256' => $file->sha256,
        ]);
    }

    public function test_same_source_file_hash_is_allowed_for_different_datasets(): void
    {
        $sha256 = hash('sha256', 'shared-source-file');
        $first = StatisticalSourceFile::factory()->create(['sha256' => $sha256]);
        $second = StatisticalSourceFile::factory()->create(['sha256' => $sha256]);

        $this->assertNotSame($first->dataset_id, $second->dataset_id);
    }

    public function test_source_file_public_id_is_unique(): void
    {
        $file = StatisticalSourceFile::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create(['public_id' => $file->public_id]);
    }

    public function test_reporting_month_must_be_between_one_and_twelve(): void
    {
        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create(['reporting_month' => 13]);
    }

    public function test_reporting_month_zero_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create(['reporting_month' => 0]);
    }

    public function test_reporting_year_and_month_must_both_be_null_or_both_be_present(): void
    {
        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create([
            'reporting_year' => 2026,
            'reporting_month' => null,
        ]);
    }

    public function test_reporting_month_without_year_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        StatisticalSourceFile::factory()->create([
            'reporting_year' => null,
            'reporting_month' => 1,
        ]);
    }

    public function test_source_file_rejects_source_from_another_dataset(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $source = StatisticalSource::factory()->create();

        $this->expectException(PriceIndicesInvariantViolation::class);

        StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'source_id' => $source->id,
        ]);
    }

    public function test_domain_relations_are_wired(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $source = StatisticalSource::factory()->for($dataset, 'dataset')->create();
        $file = StatisticalSourceFile::factory()->for($dataset, 'dataset')->create([
            'source_id' => $source->id,
        ]);
        $check = StatisticalSourceCheck::factory()->for($source, 'source')->create([
            'downloaded_file_id' => $file->id,
        ]);

        $this->assertInstanceOf(HasMany::class, $dataset->sources());
        $this->assertInstanceOf(HasMany::class, $dataset->sourceFiles());
        $this->assertInstanceOf(HasMany::class, $dataset->activeFiles());
        $this->assertInstanceOf(BelongsTo::class, $source->dataset());
        $this->assertInstanceOf(HasMany::class, $source->sourceFiles());
        $this->assertInstanceOf(HasMany::class, $source->checks());
        $this->assertInstanceOf(BelongsTo::class, $file->dataset());
        $this->assertInstanceOf(BelongsTo::class, $file->source());
        $this->assertInstanceOf(HasOne::class, $file->supersededBy());
        $this->assertInstanceOf(HasOne::class, $file->activePointer());
        $this->assertTrue($dataset->sources->contains($source));
        $this->assertTrue($source->sourceFiles->contains($file));
        $this->assertTrue($source->checks->contains($check));
        $this->assertTrue($check->downloadedFile->is($file));
    }
}
