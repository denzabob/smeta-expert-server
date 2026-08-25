<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Application\Services\ActivateStatisticalClassifierVersion;
use App\Domain\PriceIndices\Application\Services\RollbackStatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

class ClassifierActivationCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_activation_command_accepts_only_an_explicit_candidate_key(): void
    {
        $definition = Artisan::all()['price-indices:classifier:activate']->getDefinition();

        $this->assertSame(['candidate'], array_keys($definition->getArguments()));
        $this->assertFalse($definition->hasOption('latest'));
        $this->assertFalse($definition->hasOption('version'));
    }

    public function test_activation_command_passes_explicit_dates_and_outputs_only_public_identity(): void
    {
        CarbonImmutable::setTestNow('2026-08-25 14:15:16');
        $activation = Mockery::mock(ActivateStatisticalClassifierVersion::class);
        $activation->shouldReceive('activate')
            ->once()
            ->withArgs(static fn (
                string $candidate,
                $asOfDate,
                $activatedAt,
                $activatedBy,
                string $reason,
            ): bool => $candidate === 'okpd2_145_2026'
                && $asOfDate->toDateString() === '2026-08-25'
                && $activatedAt->toDateTimeString() === '2026-08-25 14:15:16'
                && $activatedBy === null
                && $reason === 'console:price-indices:classifier:activate')
            ->andReturn($this->activationResult('activated'));
        $this->app->instance(ActivateStatisticalClassifierVersion::class, $activation);

        $exitCode = Artisan::call('price-indices:classifier:activate', [
            'candidate' => 'okpd2_145_2026',
        ]);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('classifier-public-id', $output);
        $this->assertStringContainsString('target-version-public-id', $output);
        $this->assertStringContainsString('previous-version-public-id', $output);
        $this->assertStringContainsString('145/2026', $output);
        $this->assertStringContainsString('activated', $output);
        $this->assertStringNotContainsString('classifier_id', $output);
        $this->assertStringNotContainsString('/private/', $output);
    }

    public function test_activation_failure_is_controlled_and_bounded(): void
    {
        $activation = Mockery::mock(ActivateStatisticalClassifierVersion::class);
        $activation->shouldReceive('activate')->once()->andThrow(new ClassifierVersionActivationException(
            'classifier_version_future_effective',
            'A future-effective classifier version cannot become active.',
        ));
        $this->app->instance(ActivateStatisticalClassifierVersion::class, $activation);

        $exitCode = Artisan::call('price-indices:classifier:activate', ['candidate' => 'future']);

        $this->assertSame(SymfonyCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('[classifier_version_future_effective]', Artisan::output());
    }

    public function test_rollback_command_accepts_one_explicit_candidate_or_version_target(): void
    {
        $definition = Artisan::all()['price-indices:classifier:rollback']->getDefinition();

        $this->assertSame(['candidate-or-version'], array_keys($definition->getArguments()));
        $this->assertFalse($definition->hasOption('previous'));
        $this->assertFalse($definition->hasOption('latest'));
    }

    public function test_rollback_command_passes_exact_target_and_reports_rollback_or_no_op(): void
    {
        CarbonImmutable::setTestNow('2026-08-25 16:17:18');
        $rollback = Mockery::mock(RollbackStatisticalClassifierVersion::class);
        $rollback->shouldReceive('rollback')
            ->once()
            ->withArgs(static fn (
                string $target,
                $asOfDate,
                $activatedAt,
                $activatedBy,
                string $reason,
            ): bool => $target === '11111111-1111-4111-8111-111111111111'
                && $asOfDate->toDateString() === '2026-08-25'
                && $activatedAt->toDateTimeString() === '2026-08-25 16:17:18'
                && $activatedBy === null
                && $reason === 'console:price-indices:classifier:rollback')
            ->andReturn($this->activationResult('activated'));
        $this->app->instance(RollbackStatisticalClassifierVersion::class, $rollback);

        $exitCode = Artisan::call('price-indices:classifier:rollback', [
            'candidate-or-version' => '11111111-1111-4111-8111-111111111111',
        ]);
        $output = Artisan::output();

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('rolled_back', $output);
        $this->assertStringContainsString('previous-version-public-id', $output);
    }

    private function activationResult(string $status): ClassifierVersionActivationResult
    {
        return new ClassifierVersionActivationResult(
            classifierCode: 'okpd2',
            classifierPublicId: 'classifier-public-id',
            targetVersionPublicId: 'target-version-public-id',
            targetVersionLabel: '145/2026',
            effectiveFrom: '2026-07-06',
            nodeCount: 20_982,
            previousVersionPublicId: 'previous-version-public-id',
            previousVersionLabel: '144/2026',
            status: $status,
        );
    }
}
