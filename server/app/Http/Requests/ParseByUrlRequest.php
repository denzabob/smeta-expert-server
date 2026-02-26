<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParseByUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => 'required|url|max:500',
            'type' => 'required|in:plate,edge,facade,hardware',
            'region_id' => 'nullable|integer|exists:regions,id',
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'URL обязателен для парсинга.',
            'url.url' => 'Некорректный формат URL.',
            'type.required' => 'Тип материала обязателен.',
            'type.in' => 'Некорректный тип материала.',
            'region_id.exists' => 'Указанный регион не найден.',
        ];
    }
}
