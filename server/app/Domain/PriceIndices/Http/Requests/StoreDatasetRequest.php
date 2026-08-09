<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_-]*$/', 'unique:statistical_datasets,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'provider_code' => ['required', 'string', 'max:64'],
            'provider_name' => ['required', 'string', 'max:255'],
            'data_kind' => ['required', 'string', 'max:64'],
            'frequency' => ['required', 'string', 'max:32'],
            'classifier_code' => ['nullable', 'string', 'max:64'],
            'territory_scope' => ['required', 'string', 'max:64'],
            'is_enabled' => ['sometimes', 'boolean'],
            'automatic_check_enabled' => ['sometimes', 'boolean'],
            'check_schedule' => ['nullable', 'string', 'max:64'],
            'metadata_json' => ['nullable', 'array'],
        ];
    }
}
