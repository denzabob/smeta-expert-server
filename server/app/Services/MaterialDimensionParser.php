<?php

namespace App\Services;

use App\Services\MaterialDimensions\Contracts\DimensionParseStrategy;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;
use App\Services\MaterialDimensions\DimensionTextNormalizer;
use App\Services\MaterialDimensions\FailedDimensionParseCaseLogger;
use App\Services\MaterialDimensions\Strategies\EdgeWidthThicknessStrategy;
use App\Services\MaterialDimensions\Strategies\ManagedDbRuleStrategy;
use App\Services\MaterialDimensions\Strategies\PlateLxWPlusThicknessStrategy;
use App\Services\MaterialDimensions\Strategies\PlateLxWStrategy;
use App\Services\MaterialDimensions\Strategies\PlateLxWxTStrategy;

class MaterialDimensionParser
{
    /** @var array<int, DimensionParseStrategy> */
    private array $builtinStrategies;

    public function __construct(
        private readonly DimensionTextNormalizer $normalizer,
        private readonly FailedDimensionParseCaseLogger $failedCaseLogger,
        PlateLxWxTStrategy $plateLxWxTStrategy,
        PlateLxWPlusThicknessStrategy $plateLxWPlusThicknessStrategy,
        PlateLxWStrategy $plateLxWStrategy,
        EdgeWidthThicknessStrategy $edgeWidthThicknessStrategy,
        private readonly ManagedDbRuleStrategy $managedDbRuleStrategy
    ) {
        $this->builtinStrategies = [
            $plateLxWxTStrategy,
            $plateLxWPlusThicknessStrategy,
            $plateLxWStrategy,
            $edgeWidthThicknessStrategy,
        ];
    }

    public function parse(
        string $rawText,
        ?string $materialType = null,
        ?string $source = null,
        array $options = []
    ): DimensionParseResult {
        $normalizedText = $this->normalizer->normalize($rawText);
        $context = new DimensionParseContext(
            rawText: $rawText,
            normalizedText: $normalizedText,
            materialType: $materialType,
            source: $source,
            metadata: $options
        );

        if ($normalizedText === '') {
            $result = DimensionParseResult::failed($normalizedText, 'empty_input');
            $this->maybeLogFailedCase($context, $result, $options);
            return $result;
        }

        foreach ($this->builtinStrategies as $strategy) {
            $result = $strategy->apply($context);
            if ($result !== null) {
                return $result;
            }
        }

        $managedResult = $this->managedDbRuleStrategy->apply($context);
        if ($managedResult !== null) {
            return $managedResult;
        }

        $result = DimensionParseResult::failed($normalizedText, 'no_matching_rule');
        $this->maybeLogFailedCase($context, $result, $options);

        return $result;
    }

    public function mergeWithManual(
        DimensionParseResult $parsed,
        ?float $manualLengthMm,
        ?float $manualWidthMm,
        ?float $manualThicknessMm,
        bool $requireLengthWidth = false
    ): DimensionParseResult {
        $hasManual = $manualLengthMm !== null || $manualWidthMm !== null || $manualThicknessMm !== null;

        $length = $manualLengthMm ?? $parsed->lengthMm;
        $width = $manualWidthMm ?? $parsed->widthMm;
        $thickness = $manualThicknessMm ?? $parsed->thicknessMm;

        $success = $requireLengthWidth
            ? ($length !== null && $width !== null)
            : ($length !== null || $width !== null || $thickness !== null || $parsed->success);

        return new DimensionParseResult(
            success: $success,
            lengthMm: $length,
            widthMm: $width,
            thicknessMm: $thickness,
            confidence: $hasManual ? max($parsed->confidence, 0.99) : $parsed->confidence,
            source: $hasManual ? 'manual' : $parsed->source,
            ruleType: $hasManual ? 'manual_override' : $parsed->ruleType,
            strategyName: $hasManual ? 'manual_override' : $parsed->strategyName,
            normalizedText: $parsed->normalizedText,
            errorReason: $success ? null : $parsed->errorReason,
            ruleId: $parsed->ruleId,
            meta: array_merge($parsed->meta, ['manual_override_applied' => $hasManual])
        );
    }

    private function maybeLogFailedCase(
        DimensionParseContext $context,
        DimensionParseResult $result,
        array $options
    ): void {
        if (($options['log_failed'] ?? true) !== true) {
            return;
        }

        if ($result->success) {
            return;
        }

        $this->failedCaseLogger->log($context, (string) $result->errorReason, $result->toArray());
    }
}
