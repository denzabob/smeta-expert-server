<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorization is handled by Policy in the controller
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string|min:1|max:10000',
        ];
    }
}
