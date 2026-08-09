<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Domain\PriceIndices\Http\Rules\HttpsUrlTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSourceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('http_method')) {
            $this->merge(['http_method' => strtoupper((string) $this->input('http_method'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var StatisticalSource $source */
        $source = $this->route('source');
        $datasetId = $this->filled('dataset_public_id')
            ? StatisticalDataset::query()->where('public_id', $this->input('dataset_public_id'))->value('id')
            : $source->dataset_id;

        return [
            'dataset_public_id' => ['sometimes', 'required', 'uuid', 'exists:statistical_datasets,public_id'],
            'code' => ['sometimes', 'required', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('statistical_sources', 'code')->where('dataset_id', $datasetId)->ignore($source->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'source_page_url' => ['sometimes', 'nullable', 'url:https', 'max:4096'],
            'download_url_template' => ['sometimes', 'nullable', 'string', 'max:4096', new HttpsUrlTemplate()],
            'filename_template' => ['sometimes', 'nullable', 'string', 'max:255', 'not_regex:/[\\\\\/\x00]/'],
            'http_method' => ['sometimes', Rule::in(['GET', 'HEAD'])],
            'is_enabled' => ['sometimes', 'boolean'],
            'automatic_check_enabled' => ['sometimes', 'boolean'],
            'settings_json' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
