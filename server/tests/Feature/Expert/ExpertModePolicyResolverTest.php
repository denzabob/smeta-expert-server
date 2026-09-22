<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Services\Expert\ExpertModePolicyResolver;
use App\Services\Expert\ExpertModeResolution;
use App\Services\Expert\ExpertTaskRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExpertModePolicyResolverTest extends TestCase
{
    #[DataProvider('autoResolutionProvider')]
    public function test_auto_resolution_is_deterministic(
        ExpertTaskRequirements $requirements,
        string $resolvedMode,
        string $routeReason,
    ): void {
        $resolution = (new ExpertModePolicyResolver)->resolve('auto', 'сообщение', $requirements);

        $this->assertSame('auto', $resolution->requestedMode);
        $this->assertSame($resolvedMode, $resolution->resolvedMode);
        $this->assertSame($routeReason, $resolution->routeReason);
    }

    public function test_explicit_modes_are_never_silently_promoted_or_downgraded(): void
    {
        $complex = $this->requirements(materialCount: 3, scope: 'targeted_multi', requiresReasoning: true, hasExplicitComparison: true);
        $simple = $this->requirements(materialCount: 1);
        $resolver = new ExpertModePolicyResolver;

        $fast = $resolver->resolve('fast', 'сложный анализ', $complex);
        $deep = $resolver->resolve('deep', 'номер дела', $simple);

        $this->assertSame([ExpertModeResolution::FAST, 'explicit_fast'], [$fast->resolvedMode, $fast->routeReason]);
        $this->assertSame([ExpertModeResolution::DEEP, 'explicit_deep'], [$deep->resolvedMode, $deep->routeReason]);
    }

    public function test_mode_is_part_of_the_idempotency_fingerprint(): void
    {
        /** @var \App\Services\Expert\ExpertChatService $service */
        $service = (new ReflectionClass(\App\Services\Expert\ExpertChatService::class))->newInstanceWithoutConstructor();

        $fast = $service->requestFingerprint('  Один  вопрос ', ['MATERIAL-1'], 'fast');
        $auto = $service->requestFingerprint('Один вопрос', ['material-1'], 'auto');
        $deep = $service->requestFingerprint('Один вопрос', ['material-1'], 'deep');

        $this->assertNotSame($fast, $auto);
        $this->assertNotSame($auto, $deep);
        $this->assertSame($fast, $service->requestFingerprint("Один\nвопрос", ['material-1'], 'fast'));
    }

    /** @return array<string, array{ExpertTaskRequirements, string, string}> */
    public static function autoResolutionProvider(): array
    {
        $factory = static fn (array $overrides = []): ExpertTaskRequirements => new ExpertTaskRequirements(
            materialCount: $overrides['materialCount'] ?? 1,
            currentMaterialCount: $overrides['currentMaterialCount'] ?? 1,
            activeMaterialCount: $overrides['activeMaterialCount'] ?? 1,
            hasImages: $overrides['hasImages'] ?? false,
            hasPdf: $overrides['hasPdf'] ?? false,
            hasScannedPdf: $overrides['hasScannedPdf'] ?? false,
            hasSpreadsheet: $overrides['hasSpreadsheet'] ?? false,
            scope: $overrides['scope'] ?? 'single',
            coverageMode: $overrides['coverageMode'] ?? 'retrieval',
            requiresVision: $overrides['requiresVision'] ?? false,
            requiresPdfProcessing: $overrides['requiresPdfProcessing'] ?? false,
            requiresMultiDocumentPipeline: $overrides['requiresMultiDocumentPipeline'] ?? false,
            requiresReasoning: $overrides['requiresReasoning'] ?? false,
            requiresExhaustiveCoverage: $overrides['requiresExhaustiveCoverage'] ?? false,
            hasExplicitComparison: $overrides['hasExplicitComparison'] ?? false,
        );

        return [
            'single document question' => [$factory(['scope' => 'single']), 'fast', 'auto_single_document_qa'],
            'single document exhaustive fact extraction' => [$factory(['coverageMode' => 'exhaustive', 'requiresExhaustiveCoverage' => true]), 'fast', 'auto_fact_extraction'],
            'image question' => [$factory(['hasImages' => true, 'requiresVision' => true]), 'fast', 'auto_vision_qa'],
            'targeted comparison' => [$factory(['materialCount' => 2, 'currentMaterialCount' => 2, 'scope' => 'targeted_multi', 'requiresReasoning' => true, 'hasExplicitComparison' => true]), 'deep', 'auto_multi_document_reasoning'],
            'multi document reasoning' => [$factory(['materialCount' => 2, 'currentMaterialCount' => 2, 'scope' => 'targeted_multi', 'requiresReasoning' => true]), 'deep', 'auto_targeted_comparison'],
            'single document expert analysis' => [$factory(['requiresReasoning' => true]), 'deep', 'auto_complex_expert_task'],
            'exhaustive multi document' => [$factory(['materialCount' => 5, 'currentMaterialCount' => 5, 'scope' => 'exhaustive_multi', 'coverageMode' => 'exhaustive', 'requiresExhaustiveCoverage' => true]), 'deep', 'auto_exhaustive_analysis'],
        ];
    }

    private function requirements(
        int $materialCount,
        string $scope = 'single',
        bool $requiresReasoning = false,
        bool $hasExplicitComparison = false,
    ): ExpertTaskRequirements {
        return new ExpertTaskRequirements(
            materialCount: $materialCount,
            currentMaterialCount: $materialCount,
            activeMaterialCount: $materialCount,
            hasImages: false,
            hasPdf: false,
            hasScannedPdf: false,
            hasSpreadsheet: false,
            scope: $scope,
            coverageMode: 'retrieval',
            requiresVision: false,
            requiresPdfProcessing: false,
            requiresMultiDocumentPipeline: false,
            requiresReasoning: $requiresReasoning,
            requiresExhaustiveCoverage: false,
            hasExplicitComparison: $hasExplicitComparison,
        );
    }
}
