<?php

namespace App\Services\MaterialDimensions\Strategies;

use App\Services\MaterialDimensions\Contracts\DimensionParseStrategy;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;

class EdgeWidthThicknessStrategy implements DimensionParseStrategy
{
    public function name(): string
    {
        return 'edge_width_thickness';
    }

    public function apply(DimensionParseContext $context): ?DimensionParseResult
    {
        if (($context->materialType ?? '') !== 'edge') {
            return null;
        }

        $text = $context->normalizedText;

        if (!preg_match('/\b(\d{1,3})\s*x\s*(\d{1,2}(?:\.\d+)?)\b/u', $text, $m)) {
            return null;
        }

        $edgeWidth = (float) $m[1];
        $edgeThickness = (float) $m[2];

        if ($edgeWidth < 10 || $edgeWidth > 100 || $edgeThickness <= 0 || $edgeThickness > 10) {
            return null;
        }

        // DB convention for edge: width -> length_mm, thickness -> width_mm.
        return DimensionParseResult::matched(
            lengthMm: $edgeWidth,
            widthMm: $edgeThickness,
            thicknessMm: null,
            confidence: 0.90,
            source: 'builtin',
            ruleType: 'strategy',
            strategyName: $this->name(),
            normalizedText: $context->normalizedText
        );
    }
}
