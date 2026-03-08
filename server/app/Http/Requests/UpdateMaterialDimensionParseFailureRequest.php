<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaterialDimensionParseFailureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resolved_length_mm' => 'nullable|integer|min:1|max:99999',
            'resolved_width_mm' => 'nullable|integer|min:1|max:99999',
            'resolved_thickness_mm' => 'nullable|numeric|min:0.1|max:999',
            'resolution_note' => 'nullable|string|max:2000',
        ];
    }
}
