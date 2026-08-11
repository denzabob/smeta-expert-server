<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatisticalSeriesIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $prepared = [];
        foreach (['item_code', 'item_code_prefix'] as $field) {
            if ($this->has($field)) {
                $prepared[$field] = mb_strtoupper($this->normalizeWhitespace((string) $this->input($field)), 'UTF-8');
            }
        }
        if ($this->has('item_name')) {
            $prepared['item_name'] = $this->normalizeWhitespace((string) $this->input('item_name'));
        }
        if ($prepared !== []) {
            $this->merge($prepared);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_code' => ['sometimes', 'string', 'max:128'],
            'item_code_prefix' => ['sometimes', 'string', 'max:128'],
            'item_name' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['item_code', 'item_name'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = str_replace("\u{00A0}", ' ', trim($value));

        return preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    }
}
