<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use InvalidArgumentException;

final class CalculatePublicStatisticalIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'end_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'amount' => ['sometimes', 'nullable', 'string', 'max:18', 'regex:/^\d{1,15}(?:\.\d{1,2})?$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), ['start_period', 'end_period', 'amount']);
            if ($unexpected !== []) {
                $validator->errors()->add('payload', 'Запрос содержит неподдерживаемые поля.');
            }

            foreach (['start_period', 'end_period'] as $field) {
                if ($validator->errors()->has($field)) {
                    continue;
                }

                try {
                    MonthlyPeriod::parse((string) $this->input($field));
                } catch (InvalidArgumentException) {
                    $validator->errors()->add($field, 'Укажите существующий календарный месяц.');
                }
            }

            $amount = $this->input('amount');
            if (! is_string($amount) || $validator->errors()->has('amount')) {
                return;
            }

            $decimal = app(DecimalMath::class);
            if ($decimal->compare($amount, '0') <= 0
                || $decimal->compare($amount, (string) config('price_indices.public_calculation.max_amount')) > 0
            ) {
                $validator->errors()->add(
                    'amount',
                    'Сумма должна быть больше нуля и не превышать установленный предел.'
                );
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        $code = $validator->errors()->has('amount')
            ? 'invalid_amount'
            : ($validator->errors()->has('start_period') || $validator->errors()->has('end_period')
                ? 'invalid_period_range'
                : 'validation_failed');

        throw new HttpResponseException(response()->json([
            'message' => 'Проверьте параметры расчёта.',
            'code' => $code,
            'errors' => $validator->errors(),
        ], 422, ['Cache-Control' => 'no-store']));
    }
}
