<?php

namespace App\Domain\PriceIndices\Http\Requests;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use InvalidArgumentException;

final class PublicToolsIndexSeriesCalculateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_string($amount)) {
            $amount = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($amount));
            $amount = str_replace(',', '.', $amount);
            $this->merge(['amount' => $amount]);
        }
    }

    public function rules(): array
    {
        return [
            'family' => [
                'required',
                'string',
                'in:'.PublicIndexFamilyRegistry::PRODUCER_PRICES.','.PublicIndexFamilyRegistry::CONSUMER_PRICES,
            ],
            'slug' => ['required', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'start_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'end_period' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/D'],
            'amount' => ['sometimes', 'nullable', 'string', 'max:18', 'regex:/^\d{1,15}(?:\.\d{1,2})?$/D'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(
                array_keys($this->all()),
                ['family', 'slug', 'start_period', 'end_period', 'amount'],
            );
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

            if (! $validator->errors()->has('start_period')
                && ! $validator->errors()->has('end_period')
            ) {
                $start = MonthlyPeriod::parse((string) $this->input('start_period'));
                $end = MonthlyPeriod::parse((string) $this->input('end_period'));
                if ($start->compare($end) >= 0) {
                    $validator->errors()->add('end_period', 'Конечный месяц должен быть позже начального.');
                }

            }

            $amount = $this->input('amount');
            if ($amount === null || $validator->errors()->has('amount')) {
                return;
            }

            $decimal = app(DecimalMath::class);
            if ($decimal->compare((string) $amount, '0') <= 0
                || $decimal->compare((string) $amount, (string) config('price_indices.public_calculation.max_amount')) > 0
            ) {
                $validator->errors()->add(
                    'amount',
                    'Сумма должна быть больше нуля и не превышать установленный предел.',
                );
            }
        }];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Проверьте параметры расчёта.',
                'details' => $validator->errors()->toArray(),
            ],
        ], 422, ['Cache-Control' => 'no-store']));
    }
}
