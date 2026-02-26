<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddPriceObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'price_per_unit' => 'required|numeric|min:0',
            'source_url' => 'required|url|max:500',
            'region_id' => 'nullable|integer|exists:regions,id',
            'source_type' => 'nullable|in:web,manual,price_list,chrome_ext',
            'currency' => 'nullable|string|max:3',
            'availability' => 'nullable|string|max:50',
            'screenshot_path' => 'nullable|string|max:255',
            'snapshot_path' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'price_per_unit.required' => 'Цена обязательна.',
            'source_url.required' => 'URL источника обязателен для подтверждения цены.',
            'source_url.url' => 'Некорректный формат URL.',
        ];
    }
}
