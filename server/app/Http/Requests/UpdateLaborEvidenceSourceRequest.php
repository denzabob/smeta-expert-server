<?php

namespace App\Http\Requests;

use App\Models\LaborProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLaborEvidenceSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = (int) $this->user()->id;

        return [
            'region_id' => ['sometimes', 'required', 'integer', 'exists:regions,id'],
            'labor_profile_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('labor_profiles', 'id')->whereNull('deleted_at'),
            ],
            'provider_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists((new LaborProvider())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('user_id', $userId)->whereNull('deleted_at')),
            ],
            'source_title' => 'sometimes|nullable|string|max:255',
            'source_url' => 'sometimes|required|url|max:2048',
            'source_date' => 'sometimes|nullable|date',
            'employer_name' => 'sometimes|nullable|string|max:255',
            'vacancy_title' => 'sometimes|nullable|string|max:255',
            'vacancy_description' => 'sometimes|nullable|string',
            'vacancy_excerpt' => 'sometimes|nullable|string',
            'salary_raw_text' => 'sometimes|nullable|string',
            'salary_value' => 'sometimes|nullable|numeric|min:0',
            'salary_value_min' => 'sometimes|nullable|numeric|min:0',
            'salary_value_max' => 'sometimes|nullable|numeric|min:0',
            'salary_period' => ['sometimes', 'nullable', Rule::in(['hour', 'day', 'month', 'year', 'project'])],
            'hours_per_month' => 'sometimes|nullable|integer|min:1|max:744',
            'derived_hourly_rate' => 'sometimes|nullable|numeric|min:0',
            'currency' => 'sometimes|string|max:10',
            'note' => 'sometimes|nullable|string',
            'captured_via' => ['sometimes', 'required', Rule::in(['manual', 'chrome', 'import'])],
            'verification_status' => ['sometimes', 'required', Rule::in(['pending', 'verified', 'rejected'])],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'labor_profile_id.required' => 'Labor profile must be selected for evidence source.',
            'labor_profile_id.exists' => 'Selected labor profile does not exist.',
        ];
    }
}
