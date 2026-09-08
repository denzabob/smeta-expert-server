<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartStatisticalImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preview_public_id' => [
                'required',
                'uuid',
                'exists:statistical_import_previews,public_id',
            ],
        ];
    }
}
