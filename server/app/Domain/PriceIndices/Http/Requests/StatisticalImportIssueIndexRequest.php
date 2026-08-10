<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StatisticalImportIssueIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'severity' => ['sometimes', Rule::enum(StatisticalImportIssueSeverity::class)],
            'code' => ['sometimes', 'string', 'max:128'],
            'sheet_name' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['created_at', 'severity'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('price_indices.api.max_page_size', 500)],
        ];
    }
}
