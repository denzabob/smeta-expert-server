<?php

namespace App\Services\MaterialDimensions;

class DimensionParseResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?float $lengthMm,
        public readonly ?float $widthMm,
        public readonly ?float $thicknessMm,
        public readonly float $confidence,
        public readonly string $source,
        public readonly ?string $ruleType,
        public readonly ?string $strategyName,
        public readonly string $normalizedText,
        public readonly ?string $errorReason,
        public readonly ?int $ruleId = null,
        public readonly array $meta = []
    ) {
    }

    public static function matched(
        ?float $lengthMm,
        ?float $widthMm,
        ?float $thicknessMm,
        float $confidence,
        string $source,
        string $ruleType,
        string $strategyName,
        string $normalizedText,
        ?int $ruleId = null,
        array $meta = []
    ): self {
        return new self(
            success: true,
            lengthMm: $lengthMm,
            widthMm: $widthMm,
            thicknessMm: $thicknessMm,
            confidence: $confidence,
            source: $source,
            ruleType: $ruleType,
            strategyName: $strategyName,
            normalizedText: $normalizedText,
            errorReason: null,
            ruleId: $ruleId,
            meta: $meta
        );
    }

    public static function failed(
        string $normalizedText,
        string $errorReason,
        string $source = 'none',
        ?string $strategyName = null,
        array $meta = []
    ): self {
        return new self(
            success: false,
            lengthMm: null,
            widthMm: null,
            thicknessMm: null,
            confidence: 0.0,
            source: $source,
            ruleType: null,
            strategyName: $strategyName,
            normalizedText: $normalizedText,
            errorReason: $errorReason,
            ruleId: null,
            meta: $meta
        );
    }

    public function hasLengthWidth(): bool
    {
        return $this->lengthMm !== null && $this->widthMm !== null;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'length_mm' => $this->lengthMm,
            'width_mm' => $this->widthMm,
            'thickness_mm' => $this->thicknessMm,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'rule_type' => $this->ruleType,
            'strategy_name' => $this->strategyName,
            'normalized_text' => $this->normalizedText,
            'error_reason' => $this->errorReason,
            'rule_id' => $this->ruleId,
            'meta' => $this->meta,
        ];
    }
}
