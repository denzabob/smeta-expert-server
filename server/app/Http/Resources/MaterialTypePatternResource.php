<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialTypePatternResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'priority' => (int) $this->priority,
            'material_type' => $this->material_type,
            'source' => $this->source,
            'rule_type' => $this->rule_type,
            'target_field' => $this->target_field,
            'pattern' => $this->pattern,
            'flags' => $this->flags,
            'use_normalized_text' => (bool) $this->use_normalized_text,
            'example_input' => $this->example_input,
            'expected_material_type' => $this->expected_material_type,
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
