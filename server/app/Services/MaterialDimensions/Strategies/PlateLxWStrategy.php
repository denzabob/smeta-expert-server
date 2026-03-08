<?php

namespace App\Services\MaterialDimensions\Strategies;

use App\Services\MaterialDimensions\Contracts\DimensionParseStrategy;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;

class PlateLxWStrategy implements DimensionParseStrategy
{
    public function name(): string
    {
        return 'plate_lxw';
    }

    public function apply(DimensionParseContext $context): ?DimensionParseResult
    {
        if (!$this->supports($context->materialType)) {
            return null;
        }

        $text = $context->normalizedText;

        if (!preg_match('/\b(\d{3,5})\s*x\s*(\d{3,5})\b/u', $text, $m)) {
            return null;
        }

        return DimensionParseResult::matched(
            lengthMm: (float) $m[1],
            widthMm: (float) $m[2],
            thicknessMm: null,
            confidence: 0.70,
            source: 'builtin',
            ruleType: 'strategy',
            strategyName: $this->name(),
            normalizedText: $context->normalizedText
        );
    }

    private function supports(?string $materialType): bool
    {
        return $materialType === null || $materialType === 'plate';
    }
}
