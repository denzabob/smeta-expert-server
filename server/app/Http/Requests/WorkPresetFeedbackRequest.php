<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkPresetFeedbackRequest extends FormRequest
{
    /**
     * Максимальное общее количество символов во всех этапах (защита от мусора)
     */
    private const MAX_TOTAL_CHARS = 50000;

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
            'steps' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.title' => ['required', 'string', 'min:1', 'max:500'],
            'steps.*.hours' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'steps.*.basis' => ['required', 'string', 'min:1', 'max:500'],
            'steps.*.input_data' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'in:ai,manual'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->validateTotalChars($validator);
        });
    }

    /**
     * Проверка общего количества символов (защита от мусора)
     */
    private function validateTotalChars($validator): void
    {
        $steps = $this->input('steps', []);
        $totalChars = 0;
        
        foreach ($steps as $step) {
            $totalChars += mb_strlen($step['title'] ?? '');
            $totalChars += mb_strlen($step['basis'] ?? '');
            $totalChars += mb_strlen($step['input_data'] ?? '');
        }
        
        if ($totalChars > self::MAX_TOTAL_CHARS) {
            $validator->errors()->add('steps', 'Общее количество символов во всех этапах превышает допустимый лимит');
        }
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Название работы обязательно',
            'title.min' => 'Название работы должно содержать минимум 3 символа',
            'steps.required' => 'Необходимо указать хотя бы один этап',
            'steps.min' => 'Необходимо указать хотя бы один этап',
            'steps.max' => 'Максимальное количество этапов — 50',
            'steps.*.title.required' => 'Название этапа обязательно',
            'steps.*.hours.required' => 'Время этапа обязательно',
            'steps.*.hours.gt' => 'Время этапа должно быть больше 0',
            'steps.*.basis.required' => 'Основание этапа обязательно',
            'source.in' => 'Источник должен быть "ai" или "manual"',
        ];
    }
}
