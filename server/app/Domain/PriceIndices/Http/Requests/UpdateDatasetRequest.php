<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var StatisticalDataset $dataset */
        $dataset = $this->route('dataset');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('statistical_datasets', 'code')->ignore($dataset->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'provider_code' => ['sometimes', 'required', 'string', 'max:64'],
            'provider_name' => ['sometimes', 'required', 'string', 'max:255'],
            'data_kind' => ['sometimes', 'required', 'string', 'max:64'],
            'frequency' => ['sometimes', 'required', 'string', 'max:32'],
            'classifier_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'territory_scope' => ['sometimes', 'required', 'string', 'max:64'],
            'is_enabled' => ['sometimes', 'boolean'],
            'automatic_check_enabled' => ['sometimes', 'boolean'],
            'check_schedule' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata_json' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
