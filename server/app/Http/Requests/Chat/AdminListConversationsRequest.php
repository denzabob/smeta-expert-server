<?php

namespace App\Http\Requests\Chat;

use App\Enums\Chat\ConversationStatus;
use Illuminate\Foundation\Http\FormRequest;

class AdminListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // admin authorization is checked in the controller
    }

    public function rules(): array
    {
        $validStatuses = implode(',', array_map(fn ($s) => $s->value, ConversationStatus::cases()));

        return [
            'status'            => "sometimes|nullable|string|in:{$validStatuses}",
            'assigned_admin_id' => 'sometimes|nullable|integer|min:1',
            'unassigned'        => 'sometimes|boolean',
            'search'            => 'sometimes|nullable|string|max:255',
            'per_page'          => 'sometimes|integer|min:1|max:100',
        ];
    }
}
