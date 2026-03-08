<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialDimensionRuleResource extends JsonResource
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
            'config' => $this->config,
            'example_input' => $this->example_input,
            'expected_result' => $this->expected_result,
            'confidence' => (float) $this->confidence,
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
