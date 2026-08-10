<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\BeginImportValidation;
use App\Domain\PriceIndices\Application\Services\CreateStatisticalImport;
use App\Domain\PriceIndices\Application\Services\FailStatisticalImport;
use App\Domain\PriceIndices\Application\Services\MarkImportReadyForPublish;
use App\Domain\PriceIndices\Application\Services\StartStatisticalImport;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportConflict;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportIssue;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportLifecycle;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceIndicesImportLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lifecycle_allows_only_the_declared_transitions(): void
    {
        $lifecycle = app(StatisticalImportLifecycle::class);
        $allowed = [
            'pending:importing',
            'importing:validating',
            'importing:failed',
            'validating:ready_for_publish',
            'validating:failed',
            'ready_for_publish:published',
            'published:superseded',
        ];

        foreach (StatisticalImportStatus::cases() as $from) {
            foreach (StatisticalImportStatus::cases() as $to) {
                $this->assertSame(
                    in_array("{$from->value}:{$to->value}", $allowed, true),
                    $lifecycle->canTransition($from, $to),
                    "Unexpected transition {$from->value} -> {$to->value}"
                );
            }
        }
    }

    public function test_import_creation_requires_active_source_and_tracks_attempts_and_retry(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $active = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $creator = app(CreateStatisticalImport::class);
        $first = $creator->execute($dataset, $active);

        $this->assertSame(1, $first->attempt_no);
        $this->assertSame('producer_price_indices_by_product', $first->importer_code);
        $this->assertSame('1.0.0', $first->importer_version);

        $failed = app(StartStatisticalImport::class)->execute($first);
        $failed = app(FailStatisticalImport::class)->execute($failed, 'parse_failed', 'Synthetic failure');
        $retry = $creator->execute($dataset, $active, retryOf: $failed);
        $this->assertSame(2, $retry->attempt_no);
        $this->assertSame($failed->id, $retry->retry_of_import_id);
        $this->assertSame($retry->id, $failed->retries()->sole()->id);

        foreach ([SourceFileStatus::Approved, SourceFileStatus::PendingReview, SourceFileStatus::Superseded, SourceFileStatus::Rejected] as $status) {
            $source = StatisticalSourceFile::factory()->create([
                'dataset_id' => $dataset->id,
                'status' => $status,
            ]);
            try {
                $creator->execute($dataset, $source);
                $this->fail("Source status {$status->value} unexpectedly accepted.");
            } catch (PriceIndicesInvariantViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_operational_transitions_set_timestamps_and_failed_is_terminal(): void
    {
        $import = StatisticalImport::factory()->create();
        $import = app(StartStatisticalImport::class)->execute($import);
        $this->assertSame(StatisticalImportStatus::Importing, $import->status);
        $this->assertNotNull($import->started_at);

        $import = app(BeginImportValidation::class)->execute($import);
        $this->assertSame(StatisticalImportStatus::Validating, $import->status);
        $import = app(FailStatisticalImport::class)->execute($import, 'invalid_rows', 'Rows are invalid');
        $this->assertSame(StatisticalImportStatus::Failed, $import->status);
        $this->assertSame('invalid_rows', $import->failure_code);
        $this->assertNotNull($import->failed_at);
        $this->assertNotNull($import->finished_at);

        $this->expectException(\App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportTransitionNotAllowed::class);
        app(StatisticalImportLifecycle::class)->transition($import, StatisticalImportStatus::Pending);
    }

    public function test_ready_transition_enforces_validation_result_warnings_and_dedupe(): void
    {
        $warningImport = $this->validatingImport();
        StatisticalImportIssue::factory()->create([
            'import_id' => $warningImport->id,
            'severity' => StatisticalImportIssueSeverity::Warning,
        ]);
        $ready = app(MarkImportReadyForPublish::class)->execute($warningImport);
        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $ready->status);
        $this->assertNotNull($ready->ready_at);
        $this->assertNotNull($ready->finished_at);
        $this->assertSame(64, strlen($ready->successful_dedupe_key));

        $fatal = $this->validatingImport();
        StatisticalImportIssue::factory()->create([
            'import_id' => $fatal->id,
            'severity' => StatisticalImportIssueSeverity::Fatal,
        ]);
        $this->assertInvariantViolation(fn () => app(MarkImportReadyForPublish::class)->execute($fatal));

        $errors = $this->validatingImport(['errors_count' => 1]);
        $this->assertInvariantViolation(fn () => app(MarkImportReadyForPublish::class)->execute($errors));
    }

    public function test_second_success_for_same_importer_identity_conflicts_but_failed_retry_is_allowed(): void
    {
        $first = $this->validatingImport();
        app(MarkImportReadyForPublish::class)->execute($first);

        $retry = StatisticalImport::factory()->create([
            'dataset_id' => $first->dataset_id,
            'source_file_id' => $first->source_file_id,
            'importer_code' => $first->importer_code,
            'importer_version' => $first->importer_version,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Validating,
        ]);

        $this->expectException(StatisticalImportConflict::class);
        app(MarkImportReadyForPublish::class)->execute($retry);
    }

    private function validatingImport(array $attributes = []): StatisticalImport
    {
        return StatisticalImport::factory()->create($attributes + [
            'status' => StatisticalImportStatus::Validating,
        ]);
    }

    private function assertInvariantViolation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected an invariant violation.');
        } catch (PriceIndicesInvariantViolation) {
            $this->addToAssertionCount(1);
        }
    }
}
