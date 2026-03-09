<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIdeaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
            'description' => 'required|string|min:10|max:10000',
            'tags' => 'sometimes|array|max:20',
            'tags.*' => 'string|min:1|max:64',
            'attachments' => 'sometimes|array|max:5',
            'attachments.*' => 'file|mimes:png,jpg,jpeg,webp|max:5120',
        ];
    }
}
