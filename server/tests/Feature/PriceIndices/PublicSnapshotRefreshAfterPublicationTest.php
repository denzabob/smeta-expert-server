<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\PublishStatisticalImportForAdmin;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use App\Jobs\RefreshPriceIndicesPublicPagesJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use Tests\Feature\PriceIndices\Support\BuildsPublicSnapshotFixture;
use Tests\TestCase;

class PublicSnapshotRefreshAfterPublicationTest extends TestCase
{
    use BuildsPublicSnapshotFixture;
    use DatabaseTransactions;

    public function test_successful_admin_publication_dispatches_dataset_scoped_after_commit_job(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $import = StatisticalImport::factory()->create([
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);

        $result = app(PublishStatisticalImportForAdmin::class)->execute($import, $actor);

        $this->assertSame(StatisticalImportStatus::Published, $result->import->status);
        $this->assertSame(
            $result->import->id,
            StatisticalDatasetActiveImport::query()
                ->where('dataset_id', $result->import->dataset_id)
                ->value('import_id')
        );
        Queue::assertPushed(RefreshPriceIndicesPublicPagesJob::class, function ($job) use ($result): bool {
            return $job instanceof ShouldQueueAfterCommit
                && $job->datasetPublicId === $result->import->dataset->public_id
                && $job->importPublicId === $result->import->public_id;
        });
    }

    public function test_rolled_back_publication_does_not_dispatch_refresh_job(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        $import = StatisticalImport::factory()->create([
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $originalDatasetId = $import->dataset_id;
        $other = StatisticalImport::factory()->create();
        DB::table('statistical_imports')->where('id', $import->id)->update([
            'dataset_id' => $other->dataset_id,
        ]);

        try {
            app(PublishStatisticalImportForAdmin::class)->execute($import, $actor);
            $this->fail('Expected publication to roll back.');
        } catch (PriceIndicesApiException $exception) {
            $this->assertSame('dataset_mismatch', $exception->errorCode);
        }

        Queue::assertNotPushed(RefreshPriceIndicesPublicPagesJob::class);
        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $import->refresh()->status);
        $this->assertNull(StatisticalDatasetActiveImport::query()
            ->where('dataset_id', $originalDatasetId)
            ->first());
    }

    public function test_job_refreshes_only_its_dataset_and_is_idempotent(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $other = $this->publicSnapshotFixture(itemCode: '31.02.10.150');
        Log::spy();
        $job = new RefreshPriceIndicesPublicPagesJob(
            $fixture['dataset']->public_id,
            $fixture['import']->public_id,
        );

        app()->call([$job, 'handle']);
        $page = StatisticalPublicSeriesPage::query()
            ->where('dataset_id', $fixture['dataset']->id)
            ->sole();
        $publicId = $page->public_id;
        app()->call([$job, 'handle']);

        $this->assertSame(1, StatisticalPublicSeriesPage::query()
            ->where('dataset_id', $fixture['dataset']->id)
            ->count());
        $this->assertSame(0, StatisticalPublicSeriesPage::query()
            ->where('dataset_id', $other['dataset']->id)
            ->count());
        $this->assertSame($publicId, $page->fresh()->public_id);
        Log::shouldHaveReceived('info')
            ->with('Price indices public snapshot refresh completed.', Mockery::on(
                fn (array $context): bool => $context['dataset_public_id'] === $fixture['dataset']->public_id
                    && $context['import_public_id'] === $fixture['import']->public_id
                    && $context['series_scanned'] === 1
            ))
            ->twice();
    }

    public function test_refresh_failure_preserves_existing_page_and_published_import_and_is_logged(): void
    {
        $fixture = $this->publicSnapshotFixture();
        $job = new RefreshPriceIndicesPublicPagesJob(
            $fixture['dataset']->public_id,
            $fixture['import']->public_id,
        );
        app()->call([$job, 'handle']);
        $page = StatisticalPublicSeriesPage::query()->where('dataset_id', $fixture['dataset']->id)->sole();
        $publicId = $page->public_id;
        $fixture['dataset']->update(['is_enabled' => false]);
        Log::spy();

        try {
            app()->call([$job, 'handle']);
            $this->fail('Expected the dataset-scoped refresh to fail.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($publicId, $page->fresh()->public_id);
        $this->assertTrue($page->fresh()->is_indexable);
        $this->assertSame(StatisticalImportStatus::Published, $fixture['import']->refresh()->status);
        $this->assertSame(
            $fixture['import']->id,
            StatisticalDatasetActiveImport::query()
                ->where('dataset_id', $fixture['dataset']->id)
                ->value('import_id')
        );
        Log::shouldHaveReceived('error')
            ->with('Price indices public snapshot refresh failed.', Mockery::on(
                fn (array $context): bool => $context['dataset_public_id'] === $fixture['dataset']->public_id
                    && $context['import_public_id'] === $fixture['import']->public_id
                    && $context['exception'] === InvalidArgumentException::class
            ))
            ->once();
    }
}
