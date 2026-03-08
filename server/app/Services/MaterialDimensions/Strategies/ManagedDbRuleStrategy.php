<?php

namespace App\Services\MaterialDimensions\Strategies;

use App\Models\MaterialDimensionRule;
use App\Services\MaterialDimensions\Contracts\DimensionParseStrategy;
use App\Services\MaterialDimensions\DimensionParseContext;
use App\Services\MaterialDimensions\DimensionParseResult;
use Illuminate\Support\Facades\Log;

class ManagedDbRuleStrategy implements DimensionParseStrategy
{
    public function name(): string
    {
        return 'managed_db_rules';
    }

    public function apply(DimensionParseContext $context): ?DimensionParseResult
    {
        $rules = MaterialDimensionRule::query()
            ->where('is_active', true)
            ->where(function ($query) use ($context) {
                $query->whereNull('material_type');
                if ($context->materialType !== null) {
                    $query->orWhere('material_type', $context->materialType);
                }
            })
            ->where(function ($query) use ($context) {
                $query->whereNull('source');
                if ($context->source !== null) {
                    $query->orWhere('source', $context->source);
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->rule_type !== MaterialDimensionRule::RULE_TYPE_REGEX) {
                continue;
            }

            $result = $this->evaluateRegexRule(
                context: $context,
                config: $rule->config ?? [],
                confidence: (float) $rule->confidence,
                ruleType: $rule->rule_type,
                strategyName: $rule->name,
                source: 'managed_rule',
                ruleId: $rule->id,
                meta: ['rule_priority' => $rule->priority],
                onInvalidPattern: function () use ($rule): void {
                    Log::warning('material_dimensions.invalid_regex_rule', [
                        'rule_id' => $rule->id,
                        'name' => $rule->name,
                    ]);
                }
            );
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function previewRule(array $rulePayload, DimensionParseContext $context): DimensionParseResult
    {
        if (($rulePayload['rule_type'] ?? null) !== MaterialDimensionRule::RULE_TYPE_REGEX) {
            return DimensionParseResult::failed(
                normalizedText: $context->normalizedText,
                errorReason: 'unsupported_rule_type',
                source: 'preview',
                strategyName: 'managed_rule_preview'
            );
        }

        $strategyName = (string) ($rulePayload['name'] ?? 'draft_rule');
        $result = $this->evaluateRegexRule(
            context: $context,
            config: $rulePayload['config'] ?? [],
            confidence: (float) ($rulePayload['confidence'] ?? 0.75),
            ruleType: MaterialDimensionRule::RULE_TYPE_REGEX,
            strategyName: $strategyName,
            source: 'preview',
            ruleId: null,
            meta: ['preview' => true],
            onInvalidPattern: null
        );

        if ($result !== null) {
            return $result;
        }

        return DimensionParseResult::failed(
            normalizedText: $context->normalizedText,
            errorReason: 'no_matching_rule',
            source: 'preview',
            strategyName: $strategyName,
            meta: ['preview' => true]
        );
    }

    private function evaluateRegexRule(
        DimensionParseContext $context,
        array $config,
        float $confidence,
        string $ruleType,
        string $strategyName,
        string $source,
        ?int $ruleId,
        array $meta = [],
        ?callable $onInvalidPattern = null
    ): ?DimensionParseResult {
        $pattern = (string) ($config['pattern'] ?? '');
        $flags = (string) ($config['flags'] ?? 'u');

        if ($pattern === '') {
            return null;
        }

        $subject = ($config['use_normalized_text'] ?? true) ? $context->normalizedText : $context->rawText;
        $expression = '~' . $pattern . '~' . $flags;

        $matches = [];
        $matched = @preg_match($expression, $subject, $matches);

        if ($matched === false) {
            if ($onInvalidPattern !== null) {
                $onInvalidPattern();
            }
            return null;
        }

        if ($matched !== 1) {
            return null;
        }

        $length = $this->extractDimensionValue($config, $matches, 'length_mm');
        $width = $this->extractDimensionValue($config, $matches, 'width_mm');
        $thickness = $this->extractDimensionValue($config, $matches, 'thickness_mm');

        if (!$this->isAcceptableForType($context->materialType, $length, $width, $thickness)) {
            return null;
        }

        return DimensionParseResult::matched(
            lengthMm: $length,
            widthMm: $width,
            thicknessMm: $thickness,
            confidence: $confidence,
            source: $source,
            ruleType: $ruleType,
            strategyName: $strategyName,
            normalizedText: $context->normalizedText,
            ruleId: $ruleId,
            meta: $meta
        );
    }

    private function extractDimensionValue(array $config, array $matches, string $dimensionKey): ?float
    {
        $fixed = $config['fixed'][$dimensionKey] ?? null;
        if ($fixed !== null && $fixed !== '') {
            return (float) $fixed;
        }

        $captureIndex = $config['captures'][$dimensionKey] ?? null;
        if (!is_int($captureIndex) && !ctype_digit((string) $captureIndex)) {
            return null;
        }

        $index = (int) $captureIndex;
        if (!array_key_exists($index, $matches) || trim((string) $matches[$index]) === '') {
            return null;
        }

        return $this->toFloat($matches[$index]);
    }

    private function toFloat(string $raw): ?float
    {
        $clean = preg_replace('/\s+/', '', trim($raw));
        $clean = str_replace(',', '.', $clean);

        if (!is_numeric($clean)) {
            return null;
        }

        return (float) $clean;
    }

    private function isAcceptableForType(?string $materialType, ?float $length, ?float $width, ?float $thickness): bool
    {
        if (in_array($materialType, ['plate', 'edge'], true)) {
            return $length !== null && $width !== null;
        }

        return $length !== null || $width !== null || $thickness !== null;
    }
}
