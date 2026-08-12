<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use InvalidArgumentException;

final class CalculateStatisticalIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'series_public_id' => ['required', 'uuid'],
            'start_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'end_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'base_amount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,18}(?:\.\d{1,10})?$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (['start_period', 'end_period'] as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }
                try {
                    MonthlyPeriod::parse((string) $this->input($field));
                } catch (InvalidArgumentException) {
                    $validator->errors()->add($field, 'The period is not a valid calendar month.');
                }
            }

            $amount = $this->input('base_amount');
            if (is_string($amount)
                && ! $validator->errors()->has('base_amount')
                && app(DecimalMath::class)->compare($amount, '0') <= 0
            ) {
                $validator->errors()->add('base_amount', 'The base amount must be greater than zero.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        $code = $validator->errors()->has('base_amount')
            ? 'invalid_base_amount'
            : ($validator->errors()->has('start_period') || $validator->errors()->has('end_period')
                ? 'invalid_period_range'
                : 'validation_failed');

        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'code' => $code,
            'errors' => $validator->errors(),
        ], 422));
    }
}
