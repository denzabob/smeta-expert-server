<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
class ConversationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['title'=>[$this->isMethod('post')?'required':'sometimes','string','max:255']]; }
}
