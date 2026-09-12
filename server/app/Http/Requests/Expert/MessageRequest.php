<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
class MessageRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['content'=>['required','string','max:50000']]; }
}
