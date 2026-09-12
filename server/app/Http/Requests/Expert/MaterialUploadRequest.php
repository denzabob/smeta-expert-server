<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
class MaterialUploadRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return ['file'=>[
            'required',
            'extensions:'.implode(',', config('expert.material_extensions')),
            File::types(config('expert.material_extensions'))->max(config('expert.material_max_kib')),
        ]];
    }
}
