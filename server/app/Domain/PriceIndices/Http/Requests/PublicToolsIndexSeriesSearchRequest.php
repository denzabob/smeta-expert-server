<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class PublicToolsIndexSeriesSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limit = $this->query('limit', 10);
        if (is_numeric($limit) && (int) $limit > 20) {
            $this->merge(['limit' => 20]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'family' => [
                'sometimes',
                'nullable',
                'string',
                'in:'.PublicIndexFamilyRegistry::PRODUCER_PRICES.','.PublicIndexFamilyRegistry::CONSUMER_PRICES,
            ],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Проверьте параметры поиска.',
                'details' => $validator->errors()->toArray(),
            ],
        ], 422, ['Cache-Control' => 'no-store']));
    }
}
