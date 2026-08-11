<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatisticalObservationIndexRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $prepared = [];
        if ($this->has('item_code')) {
            $prepared['item_code'] = mb_strtoupper(trim((string) $this->input('item_code')), 'UTF-8');
        }
        if (is_string($this->input('missing'))
            && in_array(mb_strtolower($this->input('missing')), ['true', 'false'], true)
        ) {
            $prepared['missing'] = mb_strtolower($this->input('missing')) === 'true';
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
            'item_code' => [
                'sometimes',
                'string',
                'max:128',
                'regex:/^(?:\d{2}(?:\.\d+)+(?:\.АГ)?|\d{2}(?:\.\d+)*\.)$/u',
            ],
            'item_name' => ['sometimes', 'string', 'max:255'],
            'series_public_id' => ['sometimes', 'uuid'],
            'period_from' => ['sometimes', 'date_format:Y-m-d'],
            'period_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'missing' => ['sometimes', 'boolean'],
            'sheet_name' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['period_start', 'item_code', 'created_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('price_indices.api.max_page_size', 500)],
        ];
    }
}
