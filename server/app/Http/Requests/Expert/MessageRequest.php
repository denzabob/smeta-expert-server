<?php

namespace App\Http\Requests\Expert;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class MessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => [$this->route('assistant') === null ? 'required' : 'sometimes', 'string', 'max:50000'],
            'material_public_ids' => [
                'sometimes',
                'array',
                'max:'.max(1, (int) config('expert.material_context.max_materials_per_message', 5)),
            ],
            'material_public_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientMessageId = $this->header('X-Expert-Message-Id');

            if ($clientMessageId !== null && (! is_string($clientMessageId) || ! Str::isUuid($clientMessageId))) {
                $validator->errors()->add('message_id', 'Требуется корректный идентификатор сообщения.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $materialValidationFailed = collect($validator->errors()->keys())
            ->contains(fn (string $attribute): bool => $attribute === 'material_public_ids'
                || Str::startsWith($attribute, 'material_public_ids.'));

        if (! $materialValidationFailed) {
            parent::failedValidation($validator);
        }

        $materialPublicIds = $this->input('material_public_ids');
        $tooManyMaterials = is_array($materialPublicIds)
            && count($materialPublicIds) > max(1, (int) config('expert.material_context.max_materials_per_message', 5));

        throw new HttpResponseException(response()->json([
            'message' => $tooManyMaterials
                ? 'Выбрано слишком много материалов для одного сообщения.'
                : 'Переданы некорректные идентификаторы материалов.',
            'code' => $tooManyMaterials ? 'material_context_too_large' : 'material_context_invalid',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function clientMessageId(): ?string
    {
        $clientMessageId = $this->header('X-Expert-Message-Id');

        return is_string($clientMessageId) && Str::isUuid($clientMessageId)
            ? $clientMessageId
            : null;
    }
}
