<?php

namespace App\Http\Requests;

use App\Models\Material;
use App\Models\Operation;
use App\Models\ProjectProfileRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreManualPricingSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_type' => 'required|string|in:material,operation,labor,product',
            'target_id'   => 'required|integer|min:1',
            'value'       => 'required|numeric|gt:0',
            'unit'        => 'nullable|string|max:50',
            'source_type' => 'required|string|in:manual,url,file',
            'source_url'  => 'nullable|url|max:2048|required_if:source_type,url',
            'notes'       => 'nullable|string|max:2000',
            'file'        => 'nullable|file|max:10240',
            'files'       => 'nullable|array|max:20',
            'files.*'     => 'file|max:10240',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $targetType = $this->input('target_type');
            $targetId = (int) $this->input('target_id');

            if (!$targetType || $targetId <= 0) {
                return;
            }

            $exists = match ($targetType) {
                'material' => Material::whereKey($targetId)
                    ->where('type', '!=', Material::TYPE_FACADE)
                    ->exists(),
                'operation' => Operation::whereKey($targetId)->exists(),
                'labor' => ProjectProfileRate::whereKey($targetId)->exists(),
                'product' => Material::whereKey($targetId)
                    ->where('type', Material::TYPE_FACADE)
                    ->exists(),
                default => false,
            };

            if (!$exists) {
                $validator->errors()->add('target_id', 'The selected target is invalid.');
            }

            if ($this->input('source_type') === 'file' && !$this->hasFile('file') && !$this->hasFile('files')) {
                $validator->errors()->add('file', 'A file upload is required when source_type is file.');
            }
        });
    }
}