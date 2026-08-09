<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileTransitionNotAllowed;
use App\Domain\PriceIndices\Domain\SourceFiles\ActivateSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalDatasetActiveFile;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PriceIndicesSourceFileActivationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approved_file_becomes_active_and_creates_pointer(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->approved()->create();

        $active = app(ActivateSourceFile::class)->execute($file, $actor);
        $pointer = StatisticalDatasetActiveFile::query()->sole();

        $this->assertSame(SourceFileStatus::Active, $active->status);
        $this->assertSame($actor->id, $active->activated_by_user_id);
        $this->assertNotNull($active->activated_at);
        $this->assertSame($active->id, $pointer->source_file_id);
        $this->assertSame($active->dataset_id, $pointer->dataset_id);
        $this->assertSame($active->reporting_year, $pointer->reporting_year);
        $this->assertSame($active->reporting_month, $pointer->reporting_month);
    }

    public function test_second_version_supersedes_previous_and_moves_pointer(): void
    {
        $actor = User::factory()->create();
        $dataset = StatisticalDataset::factory()->create();
        $first = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create();
        $second = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create([
            'reporting_year' => $first->reporting_year,
            'reporting_month' => $first->reporting_month,
        ]);

        app(ActivateSourceFile::class)->execute($first, $actor);
        $active = app(ActivateSourceFile::class)->execute($second, $actor);

        $this->assertSame(SourceFileStatus::Superseded, $first->refresh()->status);
        $this->assertSame(SourceFileStatus::Active, $active->status);
        $this->assertSame($first->id, $active->supersedes_file_id);
        $this->assertSame(
            $active->id,
            StatisticalDatasetActiveFile::query()->sole()->source_file_id
        );
    }

    public function test_pending_and_rejected_files_cannot_be_activated(): void
    {
        $actor = User::factory()->create();

        foreach ([SourceFileStatus::PendingReview, SourceFileStatus::Rejected] as $status) {
            $file = StatisticalSourceFile::factory()->create(['status' => $status]);

            try {
                app(ActivateSourceFile::class)->execute($file, $actor);
                $this->fail("Status {$status->value} was activated.");
            } catch (SourceFileTransitionNotAllowed) {
                $this->assertSame($status, $file->refresh()->status);
            }
        }
    }

    public function test_file_without_period_cannot_be_activated(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->approved()->create([
            'reporting_year' => null,
            'reporting_month' => null,
        ]);

        $this->expectException(PriceIndicesInvariantViolation::class);

        app(ActivateSourceFile::class)->execute($file, $actor);
    }

    public function test_active_pointer_rejects_dataset_mismatch(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $file = StatisticalSourceFile::factory()->approved()->create();

        $this->expectException(PriceIndicesInvariantViolation::class);

        StatisticalDatasetActiveFile::query()->create([
            'dataset_id' => $dataset->id,
            'reporting_year' => $file->reporting_year,
            'reporting_month' => $file->reporting_month,
            'source_file_id' => $file->id,
            'activated_at' => now(),
        ]);
    }

    public function test_active_pointer_rejects_period_mismatch(): void
    {
        $file = StatisticalSourceFile::factory()->approved()->create();

        $this->expectException(PriceIndicesInvariantViolation::class);

        StatisticalDatasetActiveFile::query()->create([
            'dataset_id' => $file->dataset_id,
            'reporting_year' => $file->reporting_year,
            'reporting_month' => $file->reporting_month === 12 ? 11 : $file->reporting_month + 1,
            'source_file_id' => $file->id,
            'activated_at' => now(),
        ]);
    }

    public function test_database_allows_only_one_pointer_per_dataset_period(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $first = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create();
        $second = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create([
            'reporting_year' => $first->reporting_year,
            'reporting_month' => $first->reporting_month,
        ]);
        $attributes = [
            'dataset_id' => $dataset->id,
            'reporting_year' => $first->reporting_year,
            'reporting_month' => $first->reporting_month,
            'activated_at' => now(),
        ];

        StatisticalDatasetActiveFile::query()->create($attributes + ['source_file_id' => $first->id]);

        $this->expectException(QueryException::class);

        StatisticalDatasetActiveFile::query()->create($attributes + ['source_file_id' => $second->id]);
    }

    public function test_activation_rolls_back_file_and_pointer_changes_on_failure(): void
    {
        $actor = User::factory()->create();
        $dataset = StatisticalDataset::factory()->create();
        $first = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create();
        $second = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create([
            'reporting_year' => $first->reporting_year,
            'reporting_month' => $first->reporting_month,
        ]);
        app(ActivateSourceFile::class)->execute($first, $actor);

        $failOnce = true;
        StatisticalDatasetActiveFile::updating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Artificial pointer update failure.');
            }
        });

        try {
            app(ActivateSourceFile::class)->execute($second, $actor);
            $this->fail('Activation did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Artificial pointer update failure.', $exception->getMessage());
        }

        $this->assertSame(SourceFileStatus::Active, $first->refresh()->status);
        $this->assertSame(SourceFileStatus::Approved, $second->refresh()->status);
        $this->assertSame(
            $first->id,
            StatisticalDatasetActiveFile::query()->sole()->source_file_id
        );
    }

    public function test_activation_rejects_corrupt_pointer_dataset_period_mapping(): void
    {
        $actor = User::factory()->create();
        $dataset = StatisticalDataset::factory()->create();
        $target = StatisticalSourceFile::factory()->for($dataset, 'dataset')->approved()->create();
        $other = StatisticalSourceFile::factory()->approved()->create([
            'reporting_year' => $target->reporting_year,
            'reporting_month' => $target->reporting_month,
            'status' => SourceFileStatus::Active,
        ]);

        DB::table('statistical_dataset_active_files')->insert([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'dataset_id' => $dataset->id,
            'reporting_year' => $target->reporting_year,
            'reporting_month' => $target->reporting_month,
            'source_file_id' => $other->id,
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(PriceIndicesInvariantViolation::class);

        app(ActivateSourceFile::class)->execute($target, $actor);
    }
}
