<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectSourceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
