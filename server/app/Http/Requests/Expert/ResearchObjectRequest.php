<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
class ResearchObjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { $r=$this->isMethod('post')?'required':'sometimes'; return ['name'=>[$r,'string','max:255'],'type'=>['nullable','string','max:64'],'description'=>['nullable','string','max:10000'],'sort_order'=>['sometimes','integer','min:0']]; }
}
