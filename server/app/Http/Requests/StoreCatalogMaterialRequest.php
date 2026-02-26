<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'article' => 'required|string|max:255',
            'type' => 'required|in:plate,edge,facade,hardware',
            'unit' => 'required|in:м²,м.п.,шт',
            'price_per_unit' => 'required|numeric|min:0',
            'source_url' => 'required|url|max:500',
            'thickness' => 'nullable|numeric|min:0',
            'waste_factor' => 'nullable|numeric|min:1|max:2',
            'length_mm' => 'nullable|integer|min:0',
            'width_mm' => 'nullable|integer|min:0',
            'thickness_mm' => 'nullable|integer|min:0',
            'material_tag' => 'nullable|string|max:50',
            'region_id' => 'nullable|integer|exists:regions,id',
            'data_origin' => 'nullable|in:manual,url_parse,price_list,chrome_ext',
            'visibility' => 'nullable|in:private,public',
            // Observation fields
            'observation_region_id' => 'nullable|integer|exists:regions,id',
            'observation_source_type' => 'nullable|in:web,manual,price_list,chrome_ext',
            'parse_session_id' => 'nullable|integer|exists:parsing_sessions,id',
            // Facade fields (optional)
            'facade_class' => 'nullable|string|max:32',
            'facade_base_type' => 'nullable|string|max:50',
            'facade_thickness_mm' => 'nullable|integer',
            'facade_covering' => 'nullable|string|max:50',
            'facade_cover_type' => 'nullable|string|max:50',
            'facade_collection' => 'nullable|string|max:100',
            'facade_price_group_label' => 'nullable|string|max:50',
            'facade_decor_label' => 'nullable|string|max:255',
            'facade_article_optional' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'operation_ids' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название материала обязательно.',
            'article.required' => 'Артикул обязателен.',
            'type.required' => 'Тип материала обязателен.',
            'unit.required' => 'Единица измерения обязательна.',
            'price_per_unit.required' => 'Цена обязательна.',
            'source_url.required' => 'URL источника обязателен.',
            'source_url.url' => 'Некорректный формат URL.',
        ];
    }
}
