<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatisticalImportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dataset_public_id' => ['sometimes', 'uuid', 'exists:statistical_datasets,public_id'],
            'source_file_public_id' => ['sometimes', 'uuid', 'exists:statistical_source_files,public_id'],
            'status' => ['sometimes', Rule::enum(StatisticalImportStatus::class)],
            'importer_code' => ['sometimes', 'string', 'max:128'],
            'importer_version' => ['sometimes', 'string', 'max:64'],
            'created_from' => ['sometimes', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sort' => ['sometimes', Rule::in(['created_at', 'started_at', 'finished_at', 'published_at', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('price_indices.api.max_page_size', 500)],
        ];
    }
}
