<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecomposeWorkRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Авторизация через middleware
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:500'],
            'context' => ['nullable', 'array'],
            'context.domain' => ['nullable', 'string', 'max:100'],
            'context.action_type' => ['nullable', 'string', 'max:100'],
            'context.object_type' => ['nullable', 'string', 'max:100'],
            'context.material' => ['nullable', 'string', 'max:100'],
            'context.constraints' => ['nullable', 'string', 'max:500'],
            'context.site_state' => ['nullable', 'string', 'max:100'],
            'context.appliances' => ['nullable', 'string', 'max:100'],
            'context.floor_access' => ['nullable', 'string', 'max:100'],
            'desired_hours' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название работы обязательно',
            'title.min' => 'Название работы должно содержать минимум 3 символа',
            'title.max' => 'Название работы не должно превышать 500 символов',
            'desired_hours.min' => 'Желаемое время должно быть больше 0',
            'desired_hours.max' => 'Желаемое время не может превышать 1000 часов',
        ];
    }
}
