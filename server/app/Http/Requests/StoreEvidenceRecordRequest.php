<?php

namespace App\Http\Requests;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvidenceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_component'      => ['required', Rule::in(CostComponent::all())],
            'source_type'         => ['required', Rule::in(SourceType::all())],
            'capture_method'      => ['required', Rule::in(CaptureMethod::all())],
            'verification_status' => ['nullable', Rule::in(VerificationStatus::all())],
            'source_url'          => 'nullable|url|max:2048',
            'source_domain'       => 'nullable|string|max:255',
            'observed_price'      => 'nullable|numeric|min:0',
            'currency'            => 'nullable|string|max:3',
            'observed_at'         => 'nullable|date',
            'extracted_name'      => 'nullable|string|max:500',
            'extracted_article'   => 'nullable|string|max:255',
            'metadata'            => 'nullable|array',
            'confidence_score'    => 'nullable|numeric|min:0|max:100',
            'trust_score'         => 'nullable|numeric|min:0|max:100',
        ];
    }
}
