<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLaborProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'base_url' => 'nullable|url|max:2048',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer',
        ];
    }
}
