<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierCandidatePersistenceResult;
use App\Domain\PriceIndices\Application\Data\ClassifierCandidateStagingResult;
use App\Domain\PriceIndices\Application\Services\PersistTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\StageTrustedClassifierCandidate;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

class ClassifierStagingCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_command_accepts_only_the_candidate_key_as_authoritative_input(): void
    {
        $definition = Artisan::all()['price-indices:classifier:stage']->getDefinition();

        $this->assertSame(['candidate'], array_keys($definition->getArguments()));
        $this->assertFalse($definition->hasOption('url'));
        $this->assertFalse($definition->hasOption('sha'));
        $this->assertFalse($definition->hasOption('version'));
        $this->assertFalse($definition->hasOption('effective-from'));
        $this->assertFalse($definition->hasOption('parser'));
        $this->assertFalse($definition->hasOption('trust-tier'));
    }

    public function test_command_orchestrates_stage_then_persist_with_an_explicit_calendar_date_and_safe_output(): void
    {
        CarbonImmutable::setTestNow('2026-08-25 18:45:00');
        $staging = Mockery::mock(StageTrustedClassifierCandidate::class);
        $staging->shouldReceive('stage')
            ->once()
            ->with('okpd2_145_2026')
            ->ordered()
            ->andReturn($this->stagingResult());
        $persistence = Mockery::mock(PersistTrustedClassifierCandidate::class);
        $persistence->shouldReceive('persist')
            ->once()
            ->ordered()
            ->withArgs(static fn (string $candidate, $asOfDate): bool => $candidate === 'okpd2_145_2026'
                && $asOfDate instanceof CarbonImmutable
                && $asOfDate->toDateString() === '2026-08-25')
            ->andReturn($this->persistenceResult());
        $this->app->instance(StageTrustedClassifierCandidate::class, $staging);
        $this->app->instance(PersistTrustedClassifierCandidate::class, $persistence);

        $exitCode = Artisan::call('price-indices:classifier:stage', [
            'candidate' => 'okpd2_145_2026',
        ]);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('okpd2_145_2026', $output);
        $this->assertStringContainsString('classifier-public-id', $output);
        $this->assertStringContainsString('source-public-id', $output);
        $this->assertStringContainsString(str_repeat('a', 64), $output);
        $this->assertStringContainsString('import-public-id', $output);
        $this->assertStringContainsString('version-public-id', $output);
        $this->assertStringContainsString('145/2026', $output);
        $this->assertStringContainsString('20982', $output);
        $this->assertStringNotContainsString('/private/classifiers/source.zip', $output);
        $this->assertStringNotContainsString('classifier_id', $output);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);
    }

    #[DataProvider('controlledFailures')]
    public function test_controlled_failures_have_bounded_public_error_codes(
        string $internalCode,
        string $stage,
        string $publicCode,
    ): void {
        $staging = Mockery::mock(StageTrustedClassifierCandidate::class);
        $staging->shouldReceive('stage')->once()->andThrow(new ClassifierCandidateStagingException(
            $internalCode,
            'Bounded safe message.',
            $stage,
        ));
        $persistence = Mockery::mock(PersistTrustedClassifierCandidate::class);
        $persistence->shouldNotReceive('persist');
        $this->app->instance(StageTrustedClassifierCandidate::class, $staging);
        $this->app->instance(PersistTrustedClassifierCandidate::class, $persistence);

        $exitCode = Artisan::call('price-indices:classifier:stage', ['candidate' => 'candidate']);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::FAILURE, $exitCode);
        $this->assertStringContainsString("[{$publicCode}]", $output);
        $this->assertStringContainsString('Bounded safe message.', $output);
    }

    /** @return array<string, array{string, string, string}> */
    public static function controlledFailures(): array
    {
        return [
            'unknown candidate' => ['classifier_candidate_not_supported', 'descriptor', 'unknown_candidate'],
            'source absent' => ['source_artifact_not_available', 'source_lookup', 'source_artifact_not_available'],
            'source integrity' => ['source_artifact_integrity_failure', 'artifact_integrity', 'source_artifact_integrity_failure'],
            'validation' => ['candidate_node_count_mismatch', 'candidate_validation', 'candidate_validation_failure'],
            'version conflict' => ['candidate_version_conflict', 'version_preflight', 'candidate_version_conflict'],
            'import not ready' => ['candidate_import_not_ready', 'ready_import_preflight', 'candidate_import_not_ready'],
            'persistence' => ['candidate_persisted_integrity_mismatch', 'persisted_integrity', 'persistence_failure'],
        ];
    }

    public function test_unexpected_failure_is_bounded_without_exception_details(): void
    {
        $staging = Mockery::mock(StageTrustedClassifierCandidate::class);
        $staging->shouldReceive('stage')->once()->andThrow(new RuntimeException(
            'C:\\private\\artifact.zip SELECT * FROM secrets <xml> stack trace'
        ));
        $this->app->instance(StageTrustedClassifierCandidate::class, $staging);

        $exitCode = Artisan::call('price-indices:classifier:stage', ['candidate' => 'candidate']);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('[persistence_failure]', $output);
        $this->assertStringNotContainsString('artifact.zip', $output);
        $this->assertStringNotContainsString('SELECT', $output);
        $this->assertStringNotContainsString('<xml>', $output);
    }

    private function stagingResult(): ClassifierCandidateStagingResult
    {
        return new ClassifierCandidateStagingResult(
            candidateKey: 'okpd2_145_2026',
            candidateFingerprint: str_repeat('f', 64),
            versionLabel: '145/2026',
            classifierCode: 'okpd2',
            classifierPublicId: 'classifier-public-id',
            sourcePublicId: 'source-public-id',
            sourceSha256: str_repeat('a', 64),
            importPublicId: 'import-public-id',
            attempt: 1,
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            status: 'ready',
            metrics: [
                'sections_count' => 21,
                'digital_nodes_count' => 20_961,
                'total_nodes_count' => 20_982,
                'warnings_count' => 0,
                'notes_count' => 1_321,
                'level_counts' => [],
            ],
            reused: false,
            elapsedMilliseconds: 10.25,
        );
    }

    private function persistenceResult(): ClassifierCandidatePersistenceResult
    {
        return new ClassifierCandidatePersistenceResult(
            candidateKey: 'okpd2_145_2026',
            classifierCode: 'okpd2',
            classifierPublicId: 'classifier-public-id',
            sourcePublicId: 'source-public-id',
            sourceSha256: str_repeat('a', 64),
            importPublicId: 'import-public-id',
            versionPublicId: 'version-public-id',
            versionLabel: '145/2026',
            effectiveFrom: '2026-07-06',
            status: 'ready',
            nodeCount: 20_982,
            reused: false,
            parseElapsedMilliseconds: 20.5,
            persistenceElapsedMilliseconds: 30.75,
            totalElapsedMilliseconds: 55.0,
            performance: [],
        );
    }
}
