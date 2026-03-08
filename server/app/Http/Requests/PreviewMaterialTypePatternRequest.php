<?php

namespace App\Http\Requests;

use App\Models\MaterialTypePattern;
use App\Services\MaterialTypes\MaterialTypeDetectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewMaterialTypePatternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'material_type' => ['required', 'string', Rule::in(MaterialTypeDetectionService::SUPPORTED_TYPES)],
            'source' => 'nullable|string|max:64',
            'rule_type' => 'required|in:' . MaterialTypePattern::RULE_TYPE_REGEX,
            'target_field' => ['required', 'string', Rule::in(MaterialTypePattern::TARGET_FIELDS)],
            'pattern' => 'required|string|max:2000',
            'flags' => 'nullable|string|max:16',
            'use_normalized_text' => 'sometimes|boolean',
            'test_title' => 'nullable|string|max:1024|required_without:test_url',
            'test_url' => 'nullable|string|max:2048|required_without:test_title',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $pattern = trim((string) $this->input('pattern', ''));
            $flags = trim((string) $this->input('flags', 'iu'));

            if ($pattern === '') {
                return;
            }

            $expression = '~' . $pattern . '~' . $flags;
            if (@preg_match($expression, '') === false) {
                $validator->errors()->add('pattern', 'Regex pattern is invalid for the selected flags.');
            }
        });
    }
}
