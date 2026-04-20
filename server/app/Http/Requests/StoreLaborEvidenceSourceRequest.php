<?php

namespace App\Http\Requests;

use App\Models\LaborProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLaborEvidenceSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = (int) $this->user()->id;

        return [
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'labor_profile_id' => [
                'required',
                'integer',
                Rule::exists('labor_profiles', 'id')->whereNull('deleted_at'),
            ],
            'provider_id' => [
                'required',
                'integer',
                Rule::exists((new LaborProvider())->getTable(), 'id')
                    ->where(fn ($query) => $query->where('user_id', $userId)->whereNull('deleted_at')),
            ],
            'source_title' => 'nullable|string|max:255',
            'source_url' => 'required|url|max:2048',
            'source_date' => 'nullable|date',
            'employer_name' => 'nullable|string|max:255',
            'vacancy_title' => 'nullable|string|max:255',
            'vacancy_description' => 'nullable|string',
            'vacancy_excerpt' => 'nullable|string',
            'salary_raw_text' => 'nullable|string',
            'salary_value' => 'nullable|numeric|min:0',
            'salary_value_min' => 'nullable|numeric|min:0',
            'salary_value_max' => 'nullable|numeric|min:0',
            'salary_period' => ['nullable', Rule::in(['hour', 'day', 'month', 'year', 'project'])],
            'hours_per_month' => 'nullable|integer|min:1|max:744',
            'derived_hourly_rate' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'note' => 'nullable|string',
            'captured_via' => ['nullable', Rule::in(['manual', 'chrome', 'import'])],
            'verification_status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
            'is_active' => 'nullable|boolean',
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
