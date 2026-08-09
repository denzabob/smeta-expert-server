<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dataset' => ['sometimes', 'uuid', 'exists:statistical_datasets,public_id'],
            'is_enabled' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'automatic_check_enabled' => ['sometimes', Rule::in(['0', '1', 'true', 'false'])],
            'sort' => ['sometimes', Rule::in(['name', 'code', 'created_at', 'updated_at', 'next_check_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
