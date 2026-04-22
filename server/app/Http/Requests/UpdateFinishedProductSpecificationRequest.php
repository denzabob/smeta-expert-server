<?php

namespace App\Http\Requests;

use App\Models\FinishedProductSpecification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFinishedProductSpecificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'product_type' => 'sometimes|in:' . FinishedProductSpecification::TYPE_FACADE,
            'name' => 'sometimes|required|string|max:255',
            'article' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'facade_class' => 'nullable|string|max:100',
            'base_type' => 'nullable|string|max:100',
            'thickness_mm' => 'nullable|integer|min:1|max:1000',
            'covering' => 'nullable|string|max:100',
            'cover_type' => 'nullable|string|max:100',
            'collection' => 'nullable|string|max:255',
            'decor_label' => 'nullable|string|max:255',
            'price_group_label' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:5000',
            'metadata' => 'nullable|array',
        ];
    }
}
