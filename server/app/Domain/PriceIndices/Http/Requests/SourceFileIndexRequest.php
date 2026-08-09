<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SourceFileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dataset' => ['sometimes', 'uuid', 'exists:statistical_datasets,public_id'],
            'source' => ['sometimes', 'uuid', 'exists:statistical_sources,public_id'],
            'status' => ['sometimes', Rule::enum(SourceFileStatus::class)],
            'validation_status' => ['sometimes', Rule::enum(ValidationStatus::class)],
            'acquisition_method' => ['sometimes', Rule::enum(AcquisitionMethod::class)],
            'reporting_year' => ['sometimes', 'integer', 'min:1900', 'max:9999'],
            'reporting_month' => ['sometimes', 'integer', 'between:1,12'],
            'sort' => ['sometimes', Rule::in(['detected_at', 'created_at', 'reporting_year', 'reporting_month', 'file_size'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
