<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\AllocateClassifierImportAttempt;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\ClassifierImportLifecycle;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierImportTransitionNotAllowed;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassifierCandidateImportLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lifecycle_allows_only_pending_parsing_validating_ready_flow(): void
    {
        $lifecycle = app(ClassifierImportLifecycle::class);
        $import = new StatisticalClassifierImport(['status' => ClassifierImportStatus::Pending]);

        $lifecycle->transition($import, ClassifierImportStatus::Parsing);
        $this->assertSame(ClassifierImportStatus::Parsing, $import->status);
        $this->assertNotNull($import->started_at);
        $this->assertNull($import->finished_at);

        $lifecycle->transition($import, ClassifierImportStatus::Validating);
        $this->assertSame(ClassifierImportStatus::Validating, $import->status);

        $lifecycle->transition($import, ClassifierImportStatus::Ready);
        $this->assertSame(ClassifierImportStatus::Ready, $import->status);
        $this->assertNotNull($import->finished_at);

        $this->expectException(ClassifierImportTransitionNotAllowed::class);
        $lifecycle->transition($import, ClassifierImportStatus::Parsing);
    }

    public function test_failure_is_allowed_only_from_parsing_or_validating(): void
    {
        $lifecycle = app(ClassifierImportLifecycle::class);

        foreach ([ClassifierImportStatus::Parsing, ClassifierImportStatus::Validating] as $status) {
            $import = new StatisticalClassifierImport(['status' => $status]);
            $lifecycle->transition($import, ClassifierImportStatus::Failed);
            $this->assertSame(ClassifierImportStatus::Failed, $import->status);
            $this->assertNotNull($import->finished_at);
        }

        foreach ([ClassifierImportStatus::Pending, ClassifierImportStatus::Ready, ClassifierImportStatus::Failed] as $status) {
            try {
                $lifecycle->transition(
                    new StatisticalClassifierImport(['status' => $status]),
                    ClassifierImportStatus::Failed,
                );
                $this->fail("Failure transition from [{$status->value}] was accepted.");
            } catch (ClassifierImportTransitionNotAllowed) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_attempt_allocation_starts_at_one_advances_after_failure_and_locks_source(): void
    {
        [$classifier, $source] = $this->classifierAndSource();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $allocator = app(AllocateClassifierImportAttempt::class);
        $descriptor = app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');

        $first = $allocator->allocate($descriptor, $classifier, $source);
        $first->import->update(['status' => ClassifierImportStatus::Failed]);
        $second = $allocator->allocate($descriptor, $classifier, $source);

        $this->assertFalse($first->reused);
        $this->assertSame(1, $first->import->attempt);
        $this->assertFalse($second->reused);
        $this->assertSame(2, $second->import->attempt);
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'statistical_classifier_source_files')
                && str_contains($sql, 'for update'),
        ));
    }

    public function test_database_uniqueness_remains_the_last_attempt_defence(): void
    {
        [$classifier, $source] = $this->classifierAndSource();

        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create(['attempt' => 1]);

        $this->expectException(QueryException::class);

        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create(['attempt' => 1]);
    }

    /** @return array{StatisticalClassifier, StatisticalClassifierSourceFile} */
    private function classifierAndSource(): array
    {
        $descriptor = app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');
        $classifier = StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);
        $source = StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create(['sha256' => $descriptor->sourceSha256]);

        return [$classifier, $source];
    }
}
