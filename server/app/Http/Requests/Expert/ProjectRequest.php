<?php
namespace App\Http\Requests\Expert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class ProjectRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';
        return [
            'name' => [$required, 'string', 'max:255'],
            'domain' => [$required, 'string', Rule::in(['commodity', 'construction', 'other'])],
            'work_type' => [$required, 'string', Rule::in(['pretrial_research', 'court_expertise', 'specialist_opinion', 'review', 'inspection_act', 'report', 'other'])],
            'customer' => ['nullable', 'string', 'max:255'], 'object_summary' => ['nullable', 'string', 'max:20000'],
            'address' => ['nullable', 'string', 'max:500'], 'research_date' => ['nullable', 'date'],
            'research_questions' => ['nullable', 'array', 'max:100'],
            'research_questions.*.id' => ['required_with:research_questions', 'string', 'max:64'],
            'research_questions.*.order' => ['required_with:research_questions', 'integer', 'min:0'],
            'research_questions.*.text' => ['required_with:research_questions', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'active', 'review', 'completed'])],
            'initial_research_object' => ['sometimes', 'array'],
            'initial_research_object.name' => ['required_with:initial_research_object', 'string', 'max:255'],
            'initial_research_object.type' => ['nullable', 'string', 'max:64'],
            'initial_research_object.description' => ['nullable', 'string', 'max:10000'],
            'initial_research_object.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
