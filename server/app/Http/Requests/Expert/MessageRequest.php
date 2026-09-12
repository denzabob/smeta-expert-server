<?php
namespace App\Http\Requests\Expert;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class MessageRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['content'=>['required','string','max:50000']]; }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clientMessageId = $this->header('X-Expert-Message-Id');

            if ($clientMessageId !== null && (!is_string($clientMessageId) || !Str::isUuid($clientMessageId))) {
                $validator->errors()->add('message_id', 'Требуется корректный идентификатор сообщения.');
            }
        });
    }

    public function clientMessageId(): ?string
    {
        $clientMessageId = $this->header('X-Expert-Message-Id');

        return is_string($clientMessageId) && Str::isUuid($clientMessageId)
            ? $clientMessageId
            : null;
    }
}
