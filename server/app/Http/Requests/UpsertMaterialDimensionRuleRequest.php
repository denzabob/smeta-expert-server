<?php

namespace App\Http\Requests;

use App\Models\MaterialDimensionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertMaterialDimensionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:1|max:100000',
            'material_type' => 'nullable|in:plate,edge,hardware,facade,fitting',
            'source' => 'nullable|string|max:64',
            'rule_type' => 'required|in:' . MaterialDimensionRule::RULE_TYPE_REGEX,
            'config' => 'required|array',
            'config.pattern' => 'required|string|max:2000',
            'config.flags' => 'nullable|string|max:16',
            'config.use_normalized_text' => 'nullable|boolean',
            'config.captures' => 'nullable|array',
            'config.captures.length_mm' => 'nullable|integer|min:1|max:32',
            'config.captures.width_mm' => 'nullable|integer|min:1|max:32',
            'config.captures.thickness_mm' => 'nullable|integer|min:1|max:32',
            'config.fixed' => 'nullable|array',
            'config.fixed.length_mm' => 'nullable|numeric|min:0.1|max:99999',
            'config.fixed.width_mm' => 'nullable|numeric|min:0.1|max:99999',
            'config.fixed.thickness_mm' => 'nullable|numeric|min:0.1|max:999',
            'example_input' => 'nullable|string|max:1024',
            'expected_result' => 'nullable|array',
            'expected_result.length_mm' => 'nullable|numeric|min:0.1|max:99999',
            'expected_result.width_mm' => 'nullable|numeric|min:0.1|max:99999',
            'expected_result.thickness_mm' => 'nullable|numeric|min:0.1|max:999',
            'confidence' => 'sometimes|numeric|min:0|max:1',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $config = $this->input('config', []);
            $materialType = $this->input('material_type');

            $hasLength = $this->hasDimensionMapping($config, 'length_mm');
            $hasWidth = $this->hasDimensionMapping($config, 'width_mm');
            $hasThickness = $this->hasDimensionMapping($config, 'thickness_mm');

            if (!$hasLength && !$hasWidth && !$hasThickness) {
                $validator->errors()->add('config', 'Rule must map at least one dimension field.');
            }

            if (in_array($materialType, ['plate', 'edge'], true) && (!$hasLength || !$hasWidth)) {
                $validator->errors()->add('config', 'Rules for plate/edge must provide both length_mm and width_mm.');
            }

            $pattern = (string) ($config['pattern'] ?? '');
            $flags = (string) ($config['flags'] ?? 'u');
            if ($pattern !== '') {
                $expression = '~' . $pattern . '~' . $flags;
                if (@preg_match($expression, '') === false) {
                    $validator->errors()->add('config.pattern', 'Regex pattern is invalid.');
                }
            }
        });
    }

    private function hasDimensionMapping(array $config, string $dimension): bool
    {
        $capture = $config['captures'][$dimension] ?? null;
        if ($capture !== null && $capture !== '') {
            return true;
        }

        $fixed = $config['fixed'][$dimension] ?? null;
        return $fixed !== null && $fixed !== '';
    }
}
