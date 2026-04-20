<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaborEvidenceAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimetypes:image/png,image/jpeg,application/pdf',
            ],
            'type' => [
                'nullable',
                Rule::in(['screenshot', 'document']),
            ],
        ];
    }
}
