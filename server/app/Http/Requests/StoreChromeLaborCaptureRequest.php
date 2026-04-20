<?php

namespace App\Http\Requests;

use App\Models\LaborProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChromeLaborCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = (int) $this->user()->id;

        return [
            'source_url' => 'required|url|max:2048',
            'screenshot_file' => 'required|file|mimetypes:image/png,image/jpeg|max:10240',
            'provider_domain' => 'nullable|string|max:255',
            'provider_title' => 'nullable|string|max:255',
            'source_title' => 'nullable|string|max:255',
            'source_date' => 'nullable|date',
            'employer_name' => 'nullable|string|max:255',
            'vacancy_title' => 'nullable|string|max:255',
            'vacancy_description' => 'nullable|string',
            'salary_raw_text' => 'nullable|string',
            'salary_value' => 'nullable|numeric|min:0',
            'salary_value_min' => 'nullable|numeric|min:0',
            'salary_value_max' => 'nullable|numeric|min:0',
            'salary_period' => ['nullable', Rule::in(['hour', 'day', 'month', 'year', 'project'])],
            'hours_per_month' => 'nullable|integer|min:1|max:744',
            'derived_hourly_rate' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'region_id' => 'nullable|integer|exists:regions,id',
            'labor_profile_id' => [
                'required',
                'integer',
                Rule::exists((new LaborProfile())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('user_id', $userId)->whereNull('deleted_at')),
            ],
            'note' => 'nullable|string',
            'capture_mode' => ['nullable', Rule::in(['labor'])],
            'browser_context_json' => 'nullable|json',
            'selectors_json' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            'labor_profile_id.required' => 'Labor profile must be selected for evidence source.',
        ];
    }
}
