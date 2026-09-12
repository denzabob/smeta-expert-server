<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class FindingRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $r=$this->isMethod('post')?'required':'sometimes';
        return [
            'type'=>[$r,'string',Rule::in(['fact','measurement','defect','damage','non_compliance','observation','calculation','conclusion'])],
            'title'=>[$r,'string','max:255'], 'description'=>['nullable','string','max:20000'], 'value'=>['nullable','string','max:255'], 'unit'=>['nullable','string','max:64'],
            'status'=>['sometimes','string',Rule::in(['ai_proposed','expert_confirmed','expert_rejected'])],
            'research_object_public_id'=>['nullable','uuid'], 'material_public_ids'=>['sometimes','array','max:100'], 'material_public_ids.*'=>['uuid','distinct'],
        ];
    }
}
