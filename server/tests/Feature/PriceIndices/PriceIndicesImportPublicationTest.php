<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\PublishStatisticalImport;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportConflict;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceIndicesImportPublicationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_first_and_second_publication_atomically_move_the_active_pointer(): void
    {
        $actor = User::factory()->create();
        $first = StatisticalImport::factory()->create(['status' => StatisticalImportStatus::ReadyForPublish]);
        $oldObservation = StatisticalObservation::factory()->create([
            'import_id' => $first->id,
            'source_file_id' => $first->source_file_id,
        ]);

        $first = app(PublishStatisticalImport::class)->execute($first, $actor);
        $pointer = StatisticalDatasetActiveImport::query()->where('dataset_id', $first->dataset_id)->sole();
        $this->assertSame(StatisticalImportStatus::Published, $first->status);
        $this->assertSame($first->id, $pointer->import_id);

        $second = StatisticalImport::factory()->create([
            'dataset_id' => $first->dataset_id,
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $second = app(PublishStatisticalImport::class)->execute($second, $actor);

        $this->assertSame(StatisticalImportStatus::Published, $second->status);
        $this->assertSame(StatisticalImportStatus::Superseded, $first->refresh()->status);
        $this->assertSame($first->id, $second->supersedes_import_id);
        $this->assertSame($second->id, $pointer->refresh()->import_id);
        $this->assertTrue(StatisticalObservation::query()->whereKey($oldObservation->id)->exists());
    }

    public function test_publication_rejects_invalid_status_and_dataset_mismatch(): void
    {
        $actor = User::factory()->create();
        $pending = StatisticalImport::factory()->create();
        $this->assertPublicationViolation(fn () => app(PublishStatisticalImport::class)->execute($pending, $actor));

        $otherDataset = StatisticalDataset::factory()->create();
        $mismatched = StatisticalImport::factory()->create(['status' => StatisticalImportStatus::ReadyForPublish]);
        DB::table('statistical_imports')->where('id', $mismatched->id)->update(['dataset_id' => $otherDataset->id]);
        $this->assertPublicationViolation(fn () => app(PublishStatisticalImport::class)->execute($mismatched, $actor));
    }

    public function test_pointer_conflict_rolls_back_every_publication_state_change(): void
    {
        $actor = User::factory()->create();
        $first = StatisticalImport::factory()->create(['status' => StatisticalImportStatus::ReadyForPublish]);
        $first = app(PublishStatisticalImport::class)->execute($first, $actor);
        $second = StatisticalImport::factory()->create([
            'dataset_id' => $first->dataset_id,
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $otherDataset = StatisticalDataset::factory()->create();

        DB::table('statistical_dataset_active_imports')->insert([
            'public_id' => (string) Str::uuid(),
            'dataset_id' => $otherDataset->id,
            'import_id' => $second->id,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(PublishStatisticalImport::class)->execute($second, $actor);
            $this->fail('Expected the active pointer uniqueness conflict.');
        } catch (StatisticalImportConflict) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(StatisticalImportStatus::Published, $first->refresh()->status);
        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $second->refresh()->status);
        $this->assertSame(
            $first->id,
            StatisticalDatasetActiveImport::query()->where('dataset_id', $first->dataset_id)->value('import_id')
        );
    }

    private function assertPublicationViolation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected publication invariant violation.');
        } catch (PriceIndicesInvariantViolation|\App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportTransitionNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }
}
