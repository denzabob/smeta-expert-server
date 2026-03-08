<?php

namespace App\Services\MaterialDimensions\Strategies;

use App\Services\MaterialDimensions\Contracts\DimensionParseStrategy;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;

class PlateLxWPlusThicknessStrategy implements DimensionParseStrategy
{
    public function name(): string
    {
        return 'plate_lxw_plus_thickness';
    }

    public function apply(DimensionParseContext $context): ?DimensionParseResult
    {
        if (!$this->supports($context->materialType)) {
            return null;
        }

        $text = $context->normalizedText;

        if (!preg_match('/\b(\d{3,5})\s*x\s*(\d{3,5})\b/u', $text, $sizeMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $length = (float) $sizeMatch[1][0];
        $width = (float) $sizeMatch[2][0];
        $tailOffset = $sizeMatch[0][1] + strlen($sizeMatch[0][0]);
        $tail = substr($text, $tailOffset);

        $thickness = null;
        if (preg_match('/\b(\d{1,3}(?:\.\d+)?)\s*mm\b/u', $tail, $thickMatch) || preg_match('/\b(\d{1,3}(?:\.\d+)?)\s*mm\b/u', $text, $thickMatch)) {
            $thickness = (float) $thickMatch[1];
        }

        if ($thickness === null) {
            return null;
        }

        return DimensionParseResult::matched(
            lengthMm: $length,
            widthMm: $width,
            thicknessMm: $thickness,
            confidence: 0.92,
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
