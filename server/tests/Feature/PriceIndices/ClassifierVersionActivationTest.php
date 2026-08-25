<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Application\Services\ActivateStatisticalClassifierVersion;
use App\Domain\PriceIndices\Application\Services\RollbackStatisticalClassifierVersion;
use App\Domain\PriceIndices\Application\Services\SwitchStatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierPointerSwitchMode;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class ClassifierVersionActivationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_first_candidate_activation_creates_the_only_pointer_with_explicit_metadata(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$version, $descriptor] = $this->createVersion($classifier, 'fixture_v1', 'v1', '2026-01-01');
        $this->bindCandidates([$descriptor]);
        $activatedAt = CarbonImmutable::parse('2026-08-25 12:34:56');

        $result = app(ActivateStatisticalClassifierVersion::class)->activate(
            'fixture_v1',
            CarbonImmutable::parse('2026-08-25'),
            $activatedAt,
            reason: 'test:first-activation',
        );
        $pointer = StatisticalClassifierActiveVersion::query()->sole();

        $this->assertSame('activated', $result->status);
        $this->assertSame($version->public_id, $result->targetVersionPublicId);
        $this->assertNull($result->previousVersionPublicId);
        $this->assertSame($version->id, $pointer->classifier_version_id);
        $this->assertSame($activatedAt->toDateTimeString(), $pointer->activated_at->toDateTimeString());
        $this->assertNull($pointer->activated_by);
        $this->assertSame('test:first-activation', $pointer->activation_reason);
        $this->assertSame(ClassifierVersionStatus::Ready, $version->fresh()->status);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 1);
    }

    public function test_repeated_activation_is_a_no_op_that_preserves_the_original_audit_metadata(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$version, $descriptor] = $this->createVersion($classifier, 'fixture_v1', 'v1', '2026-01-01');
        $this->bindCandidates([$descriptor]);
        $service = app(ActivateStatisticalClassifierVersion::class);

        $service->activate(
            'fixture_v1',
            CarbonImmutable::parse('2026-08-25'),
            CarbonImmutable::parse('2026-08-25 10:00:00'),
            reason: 'original-reason',
        );
        $second = $service->activate(
            'fixture_v1',
            CarbonImmutable::parse('2026-08-26'),
            CarbonImmutable::parse('2026-08-26 10:00:00'),
            reason: 'replacement-reason',
        );
        $pointer = StatisticalClassifierActiveVersion::query()->sole();

        $this->assertSame('already_active', $second->status);
        $this->assertSame($version->public_id, $second->previousVersionPublicId);
        $this->assertSame('2026-08-25 10:00:00', $pointer->activated_at->toDateTimeString());
        $this->assertSame('original-reason', $pointer->activation_reason);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 1);
    }

    public function test_activation_switches_the_pointer_without_mutating_versions_nodes_imports_or_sources(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$v1, $d1] = $this->createVersion($classifier, 'fixture_v1', 'v1', '2026-01-01');
        [$v2, $d2] = $this->createVersion($classifier, 'fixture_v2', 'v2', '2026-06-01');
        $this->bindCandidates([$d1, $d2]);
        $service = app(ActivateStatisticalClassifierVersion::class);
        $before = $this->lifecycleCounts();

        $service->activate('fixture_v1', CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 10:00:00'));
        $result = $service->activate('fixture_v2', CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 11:00:00'));

        $this->assertSame('activated', $result->status);
        $this->assertSame($v1->public_id, $result->previousVersionPublicId);
        $this->assertSame($v2->id, StatisticalClassifierActiveVersion::query()->sole()->classifier_version_id);
        $this->assertSame($before, $this->lifecycleCounts());
        $this->assertSame(ClassifierVersionStatus::Ready, $v1->fresh()->status);
        $this->assertSame(ClassifierVersionStatus::Ready, $v2->fresh()->status);
    }

    public function test_future_effective_version_is_rejected_even_when_status_is_manipulated_to_ready(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$version] = $this->createVersion($classifier, 'fixture_future', 'future', '2027-01-01');

        $this->assertActivationFailure($version, 'classifier_version_future_effective');
        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);
    }

    public function test_scheduled_version_is_rejected_even_when_effective_date_is_not_future(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$version] = $this->createVersion(
            $classifier,
            'fixture_scheduled',
            'scheduled',
            '2026-01-01',
            ClassifierVersionStatus::Scheduled,
        );

        $this->assertActivationFailure($version, 'classifier_version_not_ready');
        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);
    }

    public function test_incomplete_provenance_and_node_count_mismatch_fail_closed(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$badProvenance] = $this->createVersion($classifier, 'fixture_bad_source', 'bad-source', '2026-01-01');
        $badProvenance->classifierImport->sourceFile->update([
            'trust_tier' => ClassifierSourceTrustTier::ReferenceFixture,
        ]);
        $this->assertActivationFailure($badProvenance, 'classifier_version_provenance_invalid');

        [$badCount] = $this->createVersion($classifier, 'fixture_bad_count', 'bad-count', '2026-01-01');
        $badCount->update(['node_count' => 3]);
        $badCount->classifierImport->update([
            'nodes_parsed' => 3,
            'validation_summary_json' => [
                ...$badCount->classifierImport->validation_summary_json,
                'metrics' => ['total_nodes_count' => 3],
            ],
        ]);
        $this->assertActivationFailure($badCount->fresh(), 'classifier_version_integrity_failure');

        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);
    }

    public function test_explicit_target_rollback_restores_v1_and_preserves_both_immutable_snapshots(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$v1, $d1] = $this->createVersion($classifier, 'fixture_v1', 'v1', '2026-01-01');
        [$v2, $d2] = $this->createVersion($classifier, 'fixture_v2', 'v2', '2026-06-01');
        $this->bindCandidates([$d1, $d2]);
        $activation = app(ActivateStatisticalClassifierVersion::class);
        $activation->activate('fixture_v1', CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 10:00:00'));
        $activation->activate('fixture_v2', CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 11:00:00'));
        $before = $this->lifecycleCounts();

        $result = app(RollbackStatisticalClassifierVersion::class)->rollback(
            $v1->public_id,
            CarbonImmutable::parse('2026-08-25'),
            CarbonImmutable::parse('2026-08-25 12:00:00'),
            reason: 'test:rollback',
        );
        $repeated = app(RollbackStatisticalClassifierVersion::class)->rollback(
            $v1->public_id,
            CarbonImmutable::parse('2026-08-25'),
            CarbonImmutable::parse('2026-08-25 13:00:00'),
            reason: 'must-not-overwrite',
        );
        $pointer = StatisticalClassifierActiveVersion::query()->sole();

        $this->assertSame('activated', $result->status);
        $this->assertSame($v2->public_id, $result->previousVersionPublicId);
        $this->assertSame('already_active', $repeated->status);
        $this->assertSame($v1->id, $pointer->classifier_version_id);
        $this->assertSame('2026-08-25 12:00:00', $pointer->activated_at->toDateTimeString());
        $this->assertSame('test:rollback', $pointer->activation_reason);
        $this->assertSame($before, $this->lifecycleCounts());
    }

    public function test_rollback_accepts_a_superseded_historical_target_but_rejects_missing_pointer_and_future_target(): void
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'fixture_classifier']);
        [$v1] = $this->createVersion(
            $classifier,
            'fixture_v1',
            'v1',
            '2026-01-01',
            ClassifierVersionStatus::Superseded,
        );
        [$future] = $this->createVersion($classifier, 'fixture_future', 'future', '2027-01-01');
        $rollback = app(RollbackStatisticalClassifierVersion::class);

        try {
            $rollback->rollback($v1->public_id, CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 09:00:00'));
            $this->fail('Rollback without an active pointer must fail.');
        } catch (ClassifierVersionActivationException $exception) {
            $this->assertSame('active_version_not_set', $exception->errorCode);
        }

        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $future->id,
            'activated_at' => CarbonImmutable::parse('2026-08-25 09:30:00'),
        ]);

        $restored = $rollback->rollback(
            $v1->public_id,
            CarbonImmutable::parse('2026-08-25'),
            CarbonImmutable::parse('2026-08-25 10:00:00'),
        );

        $this->assertSame('activated', $restored->status);
        $this->assertSame($v1->id, StatisticalClassifierActiveVersion::query()->sole()->classifier_version_id);

        try {
            $rollback->rollback($future->public_id, CarbonImmutable::parse('2026-08-25'), CarbonImmutable::parse('2026-08-25 11:00:00'));
            $this->fail('Future rollback target must fail.');
        } catch (ClassifierVersionActivationException $exception) {
            $this->assertSame('classifier_version_future_effective', $exception->errorCode);
        }
    }

    private function assertActivationFailure(StatisticalClassifierVersion $version, string $expectedCode): void
    {
        try {
            app(SwitchStatisticalClassifierActiveVersion::class)->switchTo(
                $version->public_id,
                CarbonImmutable::parse('2026-08-25'),
                CarbonImmutable::parse('2026-08-25 12:00:00'),
                ClassifierPointerSwitchMode::Activation,
            );
            $this->fail("Expected activation failure [{$expectedCode}].");
        } catch (ClassifierVersionActivationException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }

    /** @return array{StatisticalClassifierVersion, TrustedClassifierCandidateDescriptor} */
    private function createVersion(
        StatisticalClassifier $classifier,
        string $candidateKey,
        string $versionLabel,
        string $effectiveFrom,
        ClassifierVersionStatus $status = ClassifierVersionStatus::Ready,
    ): array {
        $sha = hash('sha256', $candidateKey);
        $descriptor = new TrustedClassifierCandidateDescriptor(
            candidateKey: $candidateKey,
            classifierCode: $classifier->code,
            versionLabel: $versionLabel,
            effectiveFrom: $effectiveFrom,
            sourceSha256: $sha,
            parserCode: 'fixture_parser',
            parserVersion: 1,
            expectedSectionsCount: 1,
            expectedDigitalNodesCount: 1,
            expectedTotalNodesCount: 2,
            expectedNotesCount: 0,
            expectedWarningsCount: 0,
            expectedLevelCounts: ['category' => 1],
            controlNodeCode: '10.10.10.100',
            controlNodeName: 'Fixture child',
            controlNodeLevel: ClassifierSemanticLevel::Category,
            controlNodeParentCode: 'A',
            controlAncestorParents: ['A' => null],
        );
        $source = StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create([
                'sha256' => $sha,
                'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
            ]);
        $summary = [
            'candidate_key' => $candidateKey,
            'candidate_fingerprint' => $descriptor->fingerprint(),
            'version_label' => $versionLabel,
            'effective_from' => $effectiveFrom,
            'source' => ['sha256' => $sha],
            'parser' => ['code' => 'fixture_parser', 'version' => 1],
            'metrics' => [
                'sections_count' => 1,
                'digital_nodes_count' => 1,
                'total_nodes_count' => 2,
                'warnings_count' => 0,
            ],
            'notes_count' => 0,
            'level_counts' => ['category' => 1],
        ];
        $import = StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create([
                'status' => ClassifierImportStatus::Ready,
                'parser_code' => 'fixture_parser',
                'parser_version' => '1',
                'nodes_parsed' => 2,
                'sections_count' => 1,
                'validation_errors_count' => 0,
                'validation_warnings_count' => 0,
                'validation_summary_json' => $summary,
            ]);
        $version = StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create([
                'version_label' => $versionLabel,
                'effective_from' => $effectiveFrom,
                'status' => $status,
                'node_count' => 2,
            ]);
        $root = StatisticalClassifierNode::factory()
            ->for($version, 'version')
            ->create([
                'code' => "{$candidateKey}-root",
                'semantic_level' => ClassifierSemanticLevel::Section,
                'formal_depth' => 0,
                'source_order' => 1,
            ]);
        StatisticalClassifierNode::factory()
            ->for($version, 'version')
            ->create([
                'code' => "{$candidateKey}-child",
                'semantic_level' => ClassifierSemanticLevel::Category,
                'formal_depth' => 1,
                'parent_node_id' => $root->id,
                'source_order' => 2,
            ]);

        return [$version, $descriptor];
    }

    /** @param list<TrustedClassifierCandidateDescriptor> $descriptors */
    private function bindCandidates(array $descriptors): void
    {
        $registry = Mockery::mock(TrustedClassifierCandidateRegistry::class);

        foreach ($descriptors as $descriptor) {
            $registry->shouldReceive('get')
                ->with($descriptor->candidateKey)
                ->andReturn($descriptor);
        }

        $this->app->instance(TrustedClassifierCandidateRegistry::class, $registry);
    }

    /** @return array{versions: int, nodes: int, imports: int, sources: int} */
    private function lifecycleCounts(): array
    {
        return [
            'versions' => StatisticalClassifierVersion::query()->count(),
            'nodes' => StatisticalClassifierNode::query()->count(),
            'imports' => StatisticalClassifierImport::query()->count(),
            'sources' => StatisticalClassifierSourceFile::query()->count(),
        ];
    }
}
