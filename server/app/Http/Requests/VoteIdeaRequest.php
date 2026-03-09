<?php

namespace App\Http\Requests;

use App\Models\IdeaVote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoteIdeaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vote_type' => ['required', 'string', Rule::in(IdeaVote::TYPES)],
        ];
    }
}
